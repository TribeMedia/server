ALTER TABLE `scheduled_task_profile` ADD `max_total_count_allowed` INTEGER  NOT NULL AFTER `last_execution_started_at`;