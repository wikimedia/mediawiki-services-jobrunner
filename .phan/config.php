<?php

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config-library.php';

$cfg['suppress_issue_types'][] = 'PhanTypeArraySuspiciousNullable';
$cfg['suppress_issue_types'][] = 'PhanTypeInvalidDimOffset';
$cfg['suppress_issue_types'][] = 'PhanTypeMismatchArgumentInternal';
$cfg['suppress_issue_types'][] = 'PhanTypeMismatchProperty';
$cfg['suppress_issue_types'][] = 'PhanTypePossiblyInvalidDimOffset';

// T311928 - ReturnTypeWillChange only exists in PHP >= 8.1; seen as a comment on PHP < 8.0
$cfg['file_list'] = array_merge(
	$cfg['file_list'],
	class_exists( ReturnTypeWillChange::class ) ? [] : [ '.phan/stubs/ReturnTypeWillChange.php' ]
);

$cfg['directory_list'][] = 'vendor/';
$cfg['exclude_analysis_directory_list'][] = 'vendor/';

return $cfg;
