<?php

$manager->addTableColumn('tasks', 'task_sender', "ENUM('Trader', 'Analyzer', 'Notifier') NULL DEFAULT NULL AFTER task_recipient");
