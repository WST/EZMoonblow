<?php

/**
 * PHP CS Fixer config for EZMoonblow.
 * Matches CONTRIBUTING.md: methods/functions/control structures — brace on same line;
 * classes/interfaces/traits/enums — brace on new line. Tabs for indentation.
 */
$finder = PhpCsFixer\Finder::create()
	->in([__DIR__ . '/lib', __DIR__ . '/tasks', __DIR__ . '/migrations'])
	->name('*.php')
	->notPath('vendor');

return (new PhpCsFixer\Config())
	->setIndent("\t")
	->setLineEnding("\n")
	->setRules([
		'braces_position' => [
			'functions_opening_brace' => 'same_line',
			'classes_opening_brace' => 'next_line_unless_newline_at_signature_end',
			'control_structures_opening_brace' => 'same_line',
		],
		'indentation_type' => true,
		'no_closing_tag' => true,
		'single_blank_line_at_eof' => true,
	])
	->setFinder($finder);
