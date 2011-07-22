<?php
/**
 * @package plugins.cuePoint
 */
class kCuePointManager implements kObjectDeletedEventConsumer
{
	/* (non-PHPdoc)
	 * @see kObjectDeletedEventConsumer::shouldConsumeDeletedEvent()
	 */
	public function shouldConsumeDeletedEvent(BaseObject $object)
	{
		if($object instanceof entry)
			return true;
			
		if($object instanceof CuePoint)
			return true;
			
		return false;
	}
	
	/* (non-PHPdoc)
	 * @see kObjectDeletedEventConsumer::objectDeleted()
	 */
	public function objectDeleted(BaseObject $object, BatchJob $raisedJob = null) 
	{
		if($object instanceof entry)
			$this->entryDeleted($object->getId());
					
		if($object instanceof CuePoint)
			$this->cuePointDeleted($object);
			
		return true;
	}
	
	/**
	 * @param CuePoint $cuePoint
	 */
	protected function cuePointDeleted(CuePoint $cuePoint) 
	{
		$c = new Criteria();
		$c->add(CuePointPeer::PARENT_ID, $cuePoint->getId());
			
		CuePointPeer::setUseCriteriaFilter(false);
		$cuePoints = CuePointPeer::doSelect($c);

		$update = new Criteria();
		$update->add(CuePointPeer::STATUS, CuePointStatus::DELETED);
			
		$con = Propel::getConnection(myDbHelper::DB_HELPER_CONN_MASTER);
		BasePeer::doUpdate($c, $update, $con);

		foreach($cuePoints as $cuePoint)
			kEventsManager::raiseEvent(new kObjectDeletedEvent($cuePoint));
	}
	
	/**
	 * @param int $entryId
	 */
	protected function entryDeleted($entryId) 
	{
		$c = new Criteria();
		$c->add(CuePointPeer::ENTRY_ID, $entryId);
			
		CuePointPeer::setUseCriteriaFilter(false);
		$cuePoints = CuePointPeer::doSelect($c);

		$update = new Criteria();
		$update->add(CuePointPeer::STATUS, CuePointStatus::DELETED);
			
		$con = Propel::getConnection(myDbHelper::DB_HELPER_CONN_MASTER);
		BasePeer::doUpdate($c, $update, $con);

		foreach($cuePoints as $cuePoint)
			kEventsManager::raiseEvent(new kObjectDeletedEvent($cuePoint));
	}
	
	/**
	 * @param SimpleXMLElement $scene
	 * @param int $partnerId
	 * @param CuePoint $newCuePoint
	 * @return CuePoint
	 */
	public static function parseXml(SimpleXMLElement $scene, $partnerId, CuePoint $newCuePoint = null)
	{
		$cuePoint = null;
		
		if(isset($scene['sceneId']) && $scene['sceneId'])
			$cuePoint = CuePointPeer::retrieveByPK($scene['sceneId']);
			
		if(!$cuePoint && isset($scene['systemName']) && $scene['systemName'])
			$cuePoint = CuePointPeer::retrieveBySystemName($scene['systemName']);
			
		if(!$cuePoint)
			$cuePoint = $newCuePoint;
		
		$cuePoint->setPartnerId($partnerId);
		$cuePoint->setStartTime(kXml::timeToInteger($scene->sceneStartTime));
	
		$tags = array();
		foreach ($scene->tags->children() as $tag)
		{
			$value = "$tag";
			if($value)
				$tags[] = $value;
		}
		$cuePoint->setTags(implode(',', $tags));
		
		$cuePoint->setEntryId($scene['entryId']);
		if(isset($scene['systemName']))
			$cuePoint->setSystemName($scene['systemName']);
			
		return $cuePoint;
	}
	
	/**
	 * @param CuePoint $cuePoint
	 * @param SimpleXMLElement $scene
	 * @return SimpleXMLElement the created scene
	 */
	public static function generateCuePointXml(CuePoint $cuePoint, SimpleXMLElement $scene)
	{
		$scene->addAttribute('sceneId', $cuePoint->getId());
		$scene->addAttribute('entryId', $cuePoint->getEntryId());
		if($cuePoint->getSystemName())
			$scene->addAttribute('systemName', kMrssManager::stringToSafeXml($cuePoint->getSystemName()));
		
		$scene->addChild('sceneStartTime', kXml::integerToTime($cuePoint->getStartTime()));
		$scene->addChild('userId', kMrssManager::stringToSafeXml($cuePoint->getPuserId()));
		
		$tags = $scene->addChild('tags');
		if($cuePoint->getTags())
			foreach(explode(',', $cuePoint->getTags()) as $tag)
				$tags->addChild('tag', kMrssManager::stringToSafeXml($tag));
			
		return $scene;
	}

	/**
	 * @param string $xmlPath
	 * @param int $partnerId
	 * @return array<CuePoint>
	 */
	public static function addFromXml($xmlPath, $partnerId)
	{
		if(!file_exists($xmlPath))
			throw new kCuePointException("XML file [$xmlPath] not found", kCuePointException::XML_FILE_NOT_FOUND);
			
		KalturaLog::debug('xml [' . file_get_contents($xmlPath) . ']');
		$xml = new DOMDocument();
		libxml_use_internal_errors(true);
		libxml_clear_errors();
		if(!$xml->load($xmlPath))
		{
			$errors = libxml_get_errors();
			$errorMessage = kXml::getLibXmlErrorDescription(file_get_contents($xmlPath));
			throw new kCuePointException("XML [$xmlPath] is invalid:\n{$errorMessage}", kCuePointException::XML_INVALID);
		}
		
		$xsdPath = SchemaService::getSchemaPath(CuePointPlugin::getApiValue(CuePointSchemaType::INGEST_API));
		KalturaLog::debug("xsd path [$xsdPath]");
		libxml_clear_errors();
		if(!$xml->schemaValidate($xsdPath))
		{
			$errorMessage = kXml::getLibXmlErrorDescription(file_get_contents($xmlPath));
			throw new kCuePointException("XML [$xmlPath] is invalid:\n{$errorMessage}", kCuePointException::XML_INVALID);
		}
		
		$pluginInstances = KalturaPluginManager::getPluginInstances('IKalturaCuePointXmlParser');
		$scenes = new SimpleXMLElement($xmlPath, null, true);
		$cuePoints = array();
		
		foreach($scenes as $scene)
		{
			$cuePoint = null;
			foreach($pluginInstances as $pluginInstance)
			{
				$cuePoint = $pluginInstance->parseXml($scene, $partnerId, $cuePoint);
				if($cuePoint)
					$cuePoint->save();
			}
			
			if($cuePoint && $cuePoint instanceof CuePoint)
			{
				KalturaLog::debug("cue point [" . get_class($cuePoint) . "] created [" . $cuePoint->getId() . "]");
				$cuePoints[] = $cuePoint;
			}
		}
		
		return $cuePoints;
	}

	/**
	 * @param array<CuePoint> $cuePoints
	 * @return string xml
	 */
	public static function generateXml(array $cuePoints)
	{
		$schemaType = CuePointPlugin::getApiValue(CuePointSchemaType::SERVE_API);
		$xsdUrl = "http://" . kConf::get('cdn_host') . "/api_v3/service/schema/action/serve/type/$schemaType";
		
		$scenes = new SimpleXMLElement('<scenes xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="' . $xsdUrl . '" />');
	
		$pluginInstances = KalturaPluginManager::getPluginInstances('IKalturaCuePointXmlParser');
		
		foreach($cuePoints as $cuePoint)
		{
			$scene = null;
			foreach($pluginInstances as $pluginInstance)
			{
				$scene = $pluginInstance->generateXml($cuePoint, $scenes, $scene);
			}
		}
		
		$xmlContent = $scenes->asXML();
	
		KalturaLog::debug("xml [$xmlContent]");
		$xml = new DOMDocument();
		libxml_use_internal_errors(true);
		libxml_clear_errors();
		if(!$xml->loadXML($xmlContent))
		{
			$errors = libxml_get_errors();
			$errorMessage = kXml::getLibXmlErrorDescription($xmlContent);
			throw new kCuePointException("XML is invalid:\n{$errorMessage}", kCuePointException::XML_INVALID);
		}
		
		$xsdPath = SchemaService::getSchemaPath($schemaType);
		KalturaLog::debug("xsd path [$xsdPath]");
		libxml_clear_errors();
		if(!$xml->schemaValidate($xsdPath))
		{
			$errorMessage = kXml::getLibXmlErrorDescription($xmlContent);
			throw new kCuePointException("XML is invalid:\n{$errorMessage}", kCuePointException::XML_INVALID);
		}
		
		return $xmlContent;
	}	
}