<?php 
ini_set("memory_limit","256M");
require_once(__DIR__ . "/../../bootstrap.php"); 
require_once(__DIR__ . "/helpers.php");

$INPUT_PATTERN = "/^[a-zA-Z0-9_]*$/";

// get inputs
$inputPage = @$_GET["page"];
$inputService = @$_GET["service"];
$inputAction = @$_GET["action"];
$inputObject = @$_GET["object"];

if ((preg_match ($INPUT_PATTERN, $inputPage) !== 1) || (preg_match ($INPUT_PATTERN, $inputService) !== 1) 
 	|| (preg_match ($INPUT_PATTERN, $inputAction) !== 1) || (preg_match ($INPUT_PATTERN, $inputObject) !== 1)) {
	
	print "Illegal input. Page, Service, Action and Object must be alpha-numeric";
	die;
}

// get cache file name
$cachePath = kConf::get("cache_root_path").'/testmeDoc';

if ($inputPage)
{
	$cacheKey = $inputPage;
}
else if ($inputService && $inputAction)
{
	$cacheKey = "actions/$inputService/$inputAction";
}
else if ($inputService)
{
	$cacheKey = "services/$inputService";
}
else if ($inputObject)
{
	$cacheKey = "objects/$inputObject";
}
else
{
	$cacheKey = 'root';
}

$cacheLeftPaneFilePath = "$cachePath/leftpane.cache";
$cacheFilePath = "$cachePath/$cacheKey.cache";

// Html headers + scripts
require_once(__DIR__ . "/header.php");

// display left pane
if (file_exists($cacheLeftPaneFilePath))
{
	print file_get_contents($cacheLeftPaneFilePath);
}
else 
{
	ob_start();
	
	require_once(__DIR__ . "/left_pane.php");
		
	$out = ob_get_contents();
	ob_end_clean();
	print $out;
	
	kFile::setFileContent($cacheLeftPaneFilePath, $out);
}

// right pane - try to return from cache
if (file_exists($cacheFilePath))
{
	print file_get_contents($cacheFilePath);
	require_once(__DIR__ . "/footer.php");

	die;
}

// right pane - not already cached - rebuild
ob_start();

?>
	<div class="right">
		<div id="doc" >
<?php 

if ($inputPage)
{
	if (in_array($inputPage, array("inout", "notifications", "overview", "terminology", "multirequest")))
		require_once(__DIR__ . "/static_doc/".$inputPage.".php");
	else
		die('Page "'.$inputPage.'" not found');
}
else if ($inputService && $inputAction)
{
	$service = $inputService;
	$action = $inputAction;
	require_once(__DIR__ . "/service_action_info.php");
}
else if ($inputService)
{
	$service = $inputService;
	require_once(__DIR__ . "/service_info.php");
}
else if ($inputObject)
{
	$object = $inputObject;
	require_once(__DIR__ . "/object_info.php");
}

?>
		</div>
	</div>
<?php 

$out = ob_get_contents();
ob_end_clean();
print $out;

kFile::setFileContent($cacheFilePath, $out);

require_once(__DIR__ . "/footer.php");
