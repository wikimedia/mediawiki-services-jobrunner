<?php

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config-library.php';

$cfg['suppress_issue_types'][] = 'PhanTypeArraySuspiciousNullable';
$cfg['suppress_issue_types'][] = 'PhanTypeInvalidDimOffset';
$cfg['suppress_issue_types'][] = 'PhanTypeMismatchArgumentInternal';
$cfg['suppress_issue_types'][] = 'PhanTypeMismatchProperty';
$cfg['suppress_issue_types'][] = 'PhanTypePossiblyInvalidDimOffset';

$cfg['directory_list'][] = 'vendor/';
$cfg['exclude_analysis_directory_list'][] = 'vendor/';

return $cfg;
