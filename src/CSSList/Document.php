<?php

declare(strict_types=1);

namespace Sabberworm\CSS\CSSList;

use Sabberworm\CSS\OutputFormat;
use Sabberworm\CSS\Parsing\ParserState;
use Sabberworm\CSS\Parsing\SourceException;
use Sabberworm\CSS\Property\Selector;

/**
 * This class represents the root of a parsed CSS file. It contains all top-level CSS contents: mostly declaration
 * blocks, but also any at-rules encountered (`Import` and `Charset`).
 */
class Document extends CSSBlockList
{
    /**
     * @throws SourceException
     *
     * @internal since V8.8.0
     */
    public static function parse(ParserState $parserState): Document
    {
        $document = new Document($parserState->currentLine());
        CSSList::parseList($parserState, $document);

        return $document;
    }

    /**
     * Returns all `Selector` objects with the requested specificity found recursively in the tree.
     *
     * Note that this does not yield the full `DeclarationBlock` that the selector belongs to
     * (and, currently, there is no way to get to that).
     *
     * @param string|null $specificitySearch
     *        An optional filter by specificity.
     *        May contain a comparison operator and a number or just a number (defaults to "==").
     *
     * @return list<Selector>
     *
     * @example `getSelectorsBySpecificity('>= 100')`
     */
    public function getSelectorsBySpecificity(?string $specificitySearch = null): array
    {
        return $this->getAllSelectors($specificitySearch);
    }

    /**
     * Overrides `render()` to make format argument optional.
     */
    public function render(?OutputFormat $outputFormat = null): string
    {
        if ($outputFormat === null) {
            $outputFormat = new OutputFormat();
        }
        return $outputFormat->getFormatter()->comments($this) . $this->renderListContents($outputFormat);
    }

    public function isRootList(): bool
    {
        return true;
    }

     /**
     * Returns all `CSSFunction` objects recursively found in the tree, no matter how deeply nested the rule sets are.
     * 
     * @param CSSList|RuleSet|string $mElement
     *        the `CSSList` or `RuleSet` to start the search from (defaults to the whole document).
     *        If a string is given, it is used as rule name filter.
     *
     * @return array<int, CSSFunction>
     */
    public function getAllFunctions($mElement = null)
    {
        if ($mElement === null) {
            $mElement = $this;
        }
        /** @var array<int, Value> $aResult */
        $aResult = [];
        $this->allFunctions($mElement, $aResult);
        return $aResult;
    }
}
