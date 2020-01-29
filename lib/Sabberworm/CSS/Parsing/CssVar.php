<?php
namespace Sabberworm\CSS\Parsing;

class CssVar {
    private $sName;
    private $mValue;

    public function __construct($sName, $mValue) {
        $this->sName = $sName;
        $this->mValue = $mValue;
    }

    public function getName() {
        return $this->sName;
    }

    public function getValue() {
        return $this->mValue;
    }
}
