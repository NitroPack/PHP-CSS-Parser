<?php

namespace Sabberworm\CSS\Parsing;

/**
* Thrown if the CSS parsers encounters a token it did not expect
*/
class UnexpectedTokenException extends \Exception {
	private $sExpected;
	private $sFound;
	// Possible values: literal, identifier, count, expression, search
	private $sMatchType;
	private $iLineNum;

	public function __construct($sExpected, $sFound, $sMatchType = 'literal', $iLineNum = 0) {
		$this->sExpected = $sExpected;
		$this->sFound = $sFound;
		$this->sMatchType = $sMatchType;
		$this->iLineNum = $iLineNum;
		$sMessage = "Token “{$sExpected}” ({$sMatchType}) not found. Got “{$sFound}”.";
		if($this->sMatchType === 'search') {
			$sMessage = "Search for “{$sExpected}” returned no results. Context: “{$sFound}”.";
		} else if($this->sMatchType === 'count') {
			$sMessage = "Next token was expected to have {$sExpected} chars. Context: “{$sFound}”.";
		} else if($this->sMatchType === 'identifier') {
			$sMessage = "Identifier expected. Got “{$sFound}”";
		} else if($this->sMatchType === 'custom') {
			$sMessage = trim("$sExpected $sFound");
		}

		if (!empty($iLineNum)) {
			$sMessage .= " [line no: $iLineNum]";
		}

		parent::__construct($sMessage);
	}
}