<?php

declare(strict_types=1);

namespace Sabberworm\CSS\RuleSet;

use Sabberworm\CSS\Comment\Comment;
use Sabberworm\CSS\Comment\CommentContainer;
use Sabberworm\CSS\CSSElement;
use Sabberworm\CSS\CSSList\CSSList;
use Sabberworm\CSS\CSSList\CSSListItem;
use Sabberworm\CSS\CSSList\Document;
use Sabberworm\CSS\CSSList\KeyFrame;
use Sabberworm\CSS\OutputFormat;
use Sabberworm\CSS\Parsing\OutputException;
use Sabberworm\CSS\Parsing\ParserState;
use Sabberworm\CSS\Parsing\UnexpectedEOFException;
use Sabberworm\CSS\Parsing\UnexpectedTokenException;
use Sabberworm\CSS\Position\Position;
use Sabberworm\CSS\Position\Positionable;
use Sabberworm\CSS\Property\Declaration;
use Sabberworm\CSS\Property\KeyframeSelector;
use Sabberworm\CSS\Property\Selector;
use Sabberworm\CSS\Settings;
use Sabberworm\CSS\Value\Color;
use Sabberworm\CSS\Value\RuleValueList;
use Sabberworm\CSS\Rule\Rule;
use Sabberworm\CSS\Value\Size;
use Sabberworm\CSS\Value\URL;
use Sabberworm\CSS\Value\Value;

/**
 * This class represents a `RuleSet` constrained by a `Selector`.
 *
 * It contains an array of selector objects (comma-separated in the CSS) as well as the rules to be applied to the
 * matching elements.
 *
 * Declaration blocks usually appear directly inside a `Document` or another `CSSList` (mostly a `MediaQuery`).
 *
 * Note that `CSSListItem` extends both `Commentable` and `Renderable`, so those interfaces must also be implemented.
 */
class DeclarationBlock implements CSSElement, CSSListItem, Positionable, DeclarationList
{
    use CommentContainer;
    use LegacyDeclarationListMethods;
    use Position;

    /**
     * @var list<Selector>
     */
    private $selectors = [];

    /**
     * @var RuleSet
     */
    private $ruleSet;

    /**
     * @param int<1, max>|null $lineNumber
     */
    public function __construct(?int $lineNumber = null)
    {
        $this->ruleSet = new RuleSet($lineNumber);
        $this->setPosition($lineNumber);
    }

    /**
     * @throws UnexpectedTokenException
     * @throws UnexpectedEOFException
     *
     * @internal since V8.8.0
     */
    public static function parse(ParserState $parserState, ?CSSList $list = null): ?DeclarationBlock
    {
        // Handle the last two cases in invalidSelectorsInFile
        if (!($list instanceof Document)) {
            $selectorBuffer = $parserState->consumeSelectorBuffer();
            if ($selectorBuffer !== '' && $parserState->getSettings()->usesLenientParsing()) {
                $parserState->consumeUntil(['}', ParserState::EOF], false, true);
                return null;
            }
        }

        $comments = [];
        $result = new DeclarationBlock($parserState->currentLine());
        try {
            $selectors = self::parseSelectors($parserState, $list, $comments);
            $result->setSelectors($selectors, $list);
            if ($parserState->comes('{')) {
                $parserState->consume(1);
            }
        } catch (UnexpectedTokenException $e) {
            if ($parserState->getSettings()->usesLenientParsing()) {
                if (!$parserState->consumeIfComes('}')) {
                    $parserState->consumeUntil(['}', ParserState::EOF], false, true);
                }
                return null;
            } else {
                throw $e;
            }
        }
        $result->setComments($comments);

        RuleSet::parseRuleSet($parserState, $result->getRuleSet());

        return $result;
    }

    /**
     * @param array<Selector|string>|string $selectors
     *
     * @throws UnexpectedTokenException
     */
    public function setSelectors($selectors, ?CSSList $list = null): void
    {
        if (\is_array($selectors)) {
            $selectorsToSet = $selectors;
        } else {
            // A string of comma-separated selectors requires parsing.
            try {
                $parserState = new ParserState($selectors, Settings::create());
                $selectorsToSet = self::parseSelectors($parserState, $list);
                if (!$parserState->isEnd()) {
                    throw new UnexpectedTokenException('EOF', 'more');
                }
            } catch (UnexpectedTokenException $exception) {
                // The exception message from parsing may refer to the faux `{` block start token,
                // which would be confusing.
                // Rethrow with a more useful message, that also includes the selector(s) string that was passed.
                throw new UnexpectedTokenException(
                    'Selector(s) string is not valid.',
                    $selectors,
                    'custom'
                );
            }
        }

        // Convert all items to a `Selector` if not already
        foreach ($selectorsToSet as $key => $selector) {
            if (!($selector instanceof Selector)) {
                if ($list === null || !($list instanceof KeyFrame)) {
                    if (!Selector::isValid($selector)) {
                        throw new UnexpectedTokenException(
                            "Selector did not match '" . Selector::SELECTOR_VALIDATION_RX . "'.",
                            $selector,
                            'custom'
                        );
                    }
                    $selectorsToSet[$key] = new Selector($selector);
                } else {
                    if (!KeyframeSelector::isValid($selector)) {
                        throw new UnexpectedTokenException(
                            "Selector did not match '" . KeyframeSelector::SELECTOR_VALIDATION_RX . "'.",
                            $selector,
                            'custom'
                        );
                    }
                    $selectorsToSet[$key] = new KeyframeSelector($selector);
                }
            }
        }

        // Discard the keys and reindex the array
        $this->selectors = \array_values($selectorsToSet);
    }

    /**
     * Remove one of the selectors of the block.
     *
     * @param Selector|string $selectorToRemove
     */
    public function removeSelector($selectorToRemove): bool
    {
        if ($selectorToRemove instanceof Selector) {
            $selectorToRemove = $selectorToRemove->getSelector();
        }
        foreach ($this->selectors as $key => $selector) {
            if ($selector->getSelector() === $selectorToRemove) {
                unset($this->selectors[$key]);
                return true;
            }
        }
        return false;
    }

    /**
     * @return list<Selector>
     */
    public function getSelectors(): array
    {
        return $this->selectors;
    }

    public function getRuleSet(): RuleSet
    {
        return $this->ruleSet;
    }

    /**
     * @see RuleSet::addDeclaration()
     */
    public function addDeclaration(Declaration $declarationToAdd, ?Declaration $sibling = null): void
    {
        $this->ruleSet->addDeclaration($declarationToAdd, $sibling);
    }

    /**
     * @return array<int<0, max>, Declaration>
     *
     * @see RuleSet::getDeclarations()
     */
    public function getDeclarations(?string $searchPattern = null): array
    {
        return $this->ruleSet->getDeclarations($searchPattern);
    }

    /**
     * @param array<Declaration> $declarations
     *
     * @see RuleSet::setDeclarations()
     */
    public function setDeclarations(array $declarations): void
    {
        $this->ruleSet->setDeclarations($declarations);
    }

    /**
     * @return array<string, Declaration>
     *
     * @see RuleSet::getDeclarationsAssociative()
     */
    public function getDeclarationsAssociative(?string $searchPattern = null): array
    {
        return $this->ruleSet->getDeclarationsAssociative($searchPattern);
    }

    /**
     * @see RuleSet::removeDeclaration()
     */
    public function removeDeclaration(Declaration $declarationToRemove): void
    {
        $this->ruleSet->removeDeclaration($declarationToRemove);
    }

    /**
     * @see RuleSet::removeMatchingDeclarations()
     */
    public function removeMatchingDeclarations(string $searchPattern): void
    {
        $this->ruleSet->removeMatchingDeclarations($searchPattern);
    }

    /**
     * @see RuleSet::removeAllDeclarations()
     */
    public function removeAllDeclarations(): void
    {
        $this->ruleSet->removeAllDeclarations();
    }

    /**
     * @return non-empty-string
     *
     * @throws OutputException
     */
    public function render(OutputFormat $outputFormat): string
    {
        $formatter = $outputFormat->getFormatter();
        $result = $formatter->comments($this);
        if (\count($this->selectors) === 0) {
            // If all the selectors have been removed, this declaration block becomes invalid
            throw new OutputException(
                'Attempt to print declaration block with missing selector',
                $this->getLineNumber()
            );
        }
        $result .= $outputFormat->getContentBeforeDeclarationBlock();
        $result .= $formatter->implode(
            $formatter->spaceBeforeSelectorSeparator() . ',' . $formatter->spaceAfterSelectorSeparator(),
            $this->selectors
        );
        $result .= $outputFormat->getContentAfterDeclarationBlockSelectors();
        $result .= $formatter->spaceBeforeOpeningBrace() . '{';
        $result .= $this->ruleSet->render($outputFormat);
        $result .= '}';
        $result .= $outputFormat->getContentAfterDeclarationBlock();

        return $result;
    }

    /**
     * @return array<string, bool|int|float|string|array<mixed>|null>
     *
     * @internal
     */
    public function getArrayRepresentation(): array
    {
        throw new \BadMethodCallException('`getArrayRepresentation` is not yet implemented for `' . self::class . '`');
    }

    /**
     * @param list<Comment> $comments
     *
     * @return list<Selector>
     *
     * @throws UnexpectedTokenException
     */
    private static function parseSelectors(ParserState $parserState, ?CSSList $list, array &$comments = []): array
    {
        $selectorClass = $list instanceof KeyFrame ? KeyFrameSelector::class : Selector::class;
        $selectors = [];

        while (true) {
            $selectors[] = $selectorClass::parse($parserState, $comments);
            if (!$parserState->consumeIfComes(',')) {
                break;
            }
        }

        return $selectors;
    }

    /**
     * Splits shorthand declarations (e.g. `margin` or `font`) into their constituent parts.
     *
     * @deprecated since 8.7.0, will be removed without substitution in version 9.0 in #511
     */
    public function expandShorthands(): void
    {
        // border must be expanded before dimensions
        $this->expandBorderShorthand();
        $this->expandDimensionsShorthand();
        $this->expandFontShorthand();
        $this->expandBackgroundShorthand();
        $this->expandListStyleShorthand();
    }

    /**
     * Creates shorthand declarations (e.g. `margin` or `font`) whenever possible.
     *
     * @deprecated since 8.7.0, will be removed without substitution in version 9.0 in #511
     */
    public function createShorthands(): void
    {
        $this->createBackgroundShorthand();
        $this->createDimensionsShorthand();
        // border must be shortened after dimensions
        $this->createBorderShorthand();
        $this->createFontShorthand();
        $this->createListStyleShorthand();
    }

    /**
     * Splits shorthand border declarations (e.g. `border: 1px red;`).
     *
     * Additional splitting happens in expandDimensionsShorthand.
     *
     * Multiple borders are not yet supported as of 3.
     *
     * @deprecated since 8.7.0, will be removed without substitution in version 9.0 in #511
     */
    public function expandBorderShorthand(): void
    {
        $aBorderRules = [
            'border',
            'border-left',
            'border-right',
            'border-top',
            'border-bottom',
        ];
        $aBorderSizes = [
            'thin',
            'medium',
            'thick',
        ];
        $aRules = $this->getRulesAssoc();
        foreach ($aBorderRules as $sBorderRule) {
            if (!isset($aRules[$sBorderRule])) {
                continue;
            }
            $oRule = $aRules[$sBorderRule];
            $mRuleValue = $oRule->getValue();
            $aValues = [];
            if (!$mRuleValue instanceof RuleValueList) {
                $aValues[] = $mRuleValue;
            } else {
                $aValues = $mRuleValue->getListComponents();
            }
            foreach ($aValues as $mValue) {
                if ($mValue instanceof Value) {
                    $mNewValue = clone $mValue;
                } else {
                    $mNewValue = $mValue;
                }
                if ($mValue instanceof Size) {
                    $sNewRuleName = $sBorderRule . '-width';
                } elseif ($mValue instanceof Color) {
                    $sNewRuleName = $sBorderRule . '-color';
                } else {
                    if (\in_array($mValue, $aBorderSizes, true)) {
                        $sNewRuleName = $sBorderRule . '-width';
                    } else {
                        $sNewRuleName = $sBorderRule . '-style';
                    }
                }
                $oNewRule = new Rule($sNewRuleName, $oRule->getLineNumber(), $oRule->getColumnNumber());
                $oNewRule->setIsImportant($oRule->getIsImportant());
                $oNewRule->addValue([$mNewValue]);
                $this->addRule($oNewRule);
            }
            $this->removeRule($oRule);
        }
    }

    /**
     * Splits shorthand dimensional declarations (e.g. `margin: 0px auto;`)
     * into their constituent parts.
     *
     * Handles `margin`, `padding`, `border-color`, `border-style` and `border-width`.
     *
     * @deprecated since 8.7.0, will be removed without substitution in version 9.0 in #511
     */
    public function expandDimensionsShorthand(): void
    {
        $aExpansions = [
            'margin' => 'margin-%s',
            'padding' => 'padding-%s',
            'border-color' => 'border-%s-color',
            'border-style' => 'border-%s-style',
            'border-width' => 'border-%s-width',
        ];
        $aRules = $this->getRulesAssoc();
        foreach ($aExpansions as $sProperty => $sExpanded) {
            if (!isset($aRules[$sProperty])) {
                continue;
            }
            $oRule = $aRules[$sProperty];
            $mRuleValue = $oRule->getValue();
            $aValues = [];
            if (!$mRuleValue instanceof RuleValueList) {
                $aValues[] = $mRuleValue;
            } else {
                $aValues = $mRuleValue->getListComponents();
            }
            $top = $right = $bottom = $left = null;
            switch (\count($aValues)) {
                case 1:
                    $top = $right = $bottom = $left = $aValues[0];
                    break;
                case 2:
                    $top = $bottom = $aValues[0];
                    $left = $right = $aValues[1];
                    break;
                case 3:
                    $top = $aValues[0];
                    $left = $right = $aValues[1];
                    $bottom = $aValues[2];
                    break;
                case 4:
                    $top = $aValues[0];
                    $right = $aValues[1];
                    $bottom = $aValues[2];
                    $left = $aValues[3];
                    break;
            }
            foreach (['top', 'right', 'bottom', 'left'] as $sPosition) {
                $oNewRule = new Rule(\sprintf($sExpanded, $sPosition), $oRule->getLineNumber(), $oRule->getColumnNumber());
                $oNewRule->setIsImportant($oRule->getIsImportant());
                $oNewRule->addValue(${$sPosition});
                $this->addRule($oNewRule);
            }
            $this->removeRule($oRule);
        }
    }

    /**
     * Converts shorthand font declarations
     * (e.g. `font: 300 italic 11px/14px verdana, helvetica, sans-serif;`)
     * into their constituent parts.
     *
     * @deprecated since 8.7.0, will be removed without substitution in version 9.0 in #511
     */
    public function expandFontShorthand(): void
    {
        $aRules = $this->getRulesAssoc();
        if (!isset($aRules['font'])) {
            return;
        }
        $oRule = $aRules['font'];
        // reset properties to 'normal' per http://www.w3.org/TR/21/fonts.html#font-shorthand
        $aFontProperties = [
            'font-style' => 'normal',
            'font-variant' => 'normal',
            'font-weight' => 'normal',
            'font-size' => 'normal',
            'line-height' => 'normal',
        ];
        $mRuleValue = $oRule->getValue();
        $aValues = [];
        if (!$mRuleValue instanceof RuleValueList) {
            $aValues[] = $mRuleValue;
        } else {
            $aValues = $mRuleValue->getListComponents();
        }
        foreach ($aValues as $mValue) {
            if (!$mValue instanceof Value) {
                $mValue = \mb_strtolower($mValue);
            }
            if (\in_array($mValue, ['normal', 'inherit'], true)) {
                foreach (['font-style', 'font-weight', 'font-variant'] as $sProperty) {
                    if (!isset($aFontProperties[$sProperty])) {
                        $aFontProperties[$sProperty] = $mValue;
                    }
                }
            } elseif (\in_array($mValue, ['italic', 'oblique'], true)) {
                $aFontProperties['font-style'] = $mValue;
            } elseif ($mValue == 'small-caps') {
                $aFontProperties['font-variant'] = $mValue;
            } elseif (
                \in_array($mValue, ['bold', 'bolder', 'lighter'], true)
                || ($mValue instanceof Size && \in_array($mValue->getSize(), \range(100.0, 900.0, 100.0), true))
            ) {
                $aFontProperties['font-weight'] = $mValue;
            } elseif ($mValue instanceof RuleValueList && $mValue->getListSeparator() == '/') {
                [$oSize, $oHeight] = $mValue->getListComponents();
                $aFontProperties['font-size'] = $oSize;
                $aFontProperties['line-height'] = $oHeight;
            } elseif ($mValue instanceof Size && $mValue->getUnit() !== null) {
                $aFontProperties['font-size'] = $mValue;
            } else {
                $aFontProperties['font-family'] = $mValue;
            }
        }
        foreach ($aFontProperties as $sProperty => $mValue) {
            $oNewRule = new Rule($sProperty, $oRule->getLineNumber(), $oRule->getColumnNumber());
            $oNewRule->addValue($mValue);
            $oNewRule->setIsImportant($oRule->getIsImportant());
            $this->addRule($oNewRule);
        }
        $this->removeRule($oRule);
    }

    /**
     * Converts shorthand background declarations
     * (e.g. `background: url("chess.png") gray 50% repeat fixed;`)
     * into their constituent parts.
     *
     * @see http://www.w3.org/TR/21/colors.html#propdef-background
     *
     * @deprecated since 8.7.0, will be removed without substitution in version 9.0 in #511
     */
    public function expandBackgroundShorthand(): void
    {
        $aRules = $this->getRulesAssoc();
        if (!isset($aRules['background'])) {
            return;
        }
        $oRule = $aRules['background'];
        $aBgProperties = [
            'background-color' => ['transparent'],
            'background-image' => ['none'],
            'background-repeat' => ['repeat'],
            'background-attachment' => ['scroll'],
            'background-position' => [
                new Size(0, '%', false, $this->lineNumber),
                new Size(0, '%', false, $this->lineNumber),
            ],
        ];
        $mRuleValue = $oRule->getValue();
        $aValues = [];
        if (!$mRuleValue instanceof RuleValueList) {
            $aValues[] = $mRuleValue;
        } else {
            $aValues = $mRuleValue->getListComponents();
        }
        if (\count($aValues) == 1 && $aValues[0] == 'inherit') {
            foreach ($aBgProperties as $sProperty => $mValue) {
                $oNewRule = new Rule($sProperty, $oRule->getLineNumber(), $oRule->getColumnNumber());
                $oNewRule->addValue('inherit');
                $oNewRule->setIsImportant($oRule->getIsImportant());
                $this->addRule($oNewRule);
            }
            $this->removeRule($oRule);
            return;
        }
        $iNumBgPos = 0;
        foreach ($aValues as $mValue) {
            if (!$mValue instanceof Value) {
                $mValue = \mb_strtolower($mValue);
            }
            if ($mValue instanceof URL) {
                $aBgProperties['background-image'] = $mValue;
            } elseif ($mValue instanceof Color) {
                $aBgProperties['background-color'] = $mValue;
            } elseif (\in_array($mValue, ['scroll', 'fixed'], true)) {
                $aBgProperties['background-attachment'] = $mValue;
            } elseif (\in_array($mValue, ['repeat', 'no-repeat', 'repeat-x', 'repeat-y'], true)) {
                $aBgProperties['background-repeat'] = $mValue;
            } elseif (
                \in_array($mValue, ['left', 'center', 'right', 'top', 'bottom'], true)
                || $mValue instanceof Size
            ) {
                if ($iNumBgPos == 0) {
                    $aBgProperties['background-position'][0] = $mValue;
                    $aBgProperties['background-position'][1] = 'center';
                } else {
                    $aBgProperties['background-position'][$iNumBgPos] = $mValue;
                }
                $iNumBgPos++;
            }
        }
        foreach ($aBgProperties as $sProperty => $mValue) {
            $oNewRule = new Rule($sProperty, $oRule->getLineNumber(), $oRule->getColumnNumber());
            $oNewRule->setIsImportant($oRule->getIsImportant());
            $oNewRule->addValue($mValue);
            $this->addRule($oNewRule);
        }
        $this->removeRule($oRule);
    }

    /**
     * @deprecated since 8.7.0, will be removed without substitution in version 9.0 in #511
     */
    public function expandListStyleShorthand(): void
    {
        $aListProperties = [
            'list-style-type' => 'disc',
            'list-style-position' => 'outside',
            'list-style-image' => 'none',
        ];
        $aListStyleTypes = [
            'none',
            'disc',
            'circle',
            'square',
            'decimal-leading-zero',
            'decimal',
            'lower-roman',
            'upper-roman',
            'lower-greek',
            'lower-alpha',
            'lower-latin',
            'upper-alpha',
            'upper-latin',
            'hebrew',
            'armenian',
            'georgian',
            'cjk-ideographic',
            'hiragana',
            'hira-gana-iroha',
            'katakana-iroha',
            'katakana',
        ];
        $aListStylePositions = [
            'inside',
            'outside',
        ];
        $aRules = $this->getRulesAssoc();
        if (!isset($aRules['list-style'])) {
            return;
        }
        $oRule = $aRules['list-style'];
        $mRuleValue = $oRule->getValue();
        $aValues = [];
        if (!$mRuleValue instanceof RuleValueList) {
            $aValues[] = $mRuleValue;
        } else {
            $aValues = $mRuleValue->getListComponents();
        }
        if (\count($aValues) == 1 && $aValues[0] == 'inherit') {
            foreach ($aListProperties as $sProperty => $mValue) {
                $oNewRule = new Rule($sProperty, $oRule->getLineNumber(), $oRule->getColumnNumber());
                $oNewRule->addValue('inherit');
                $oNewRule->setIsImportant($oRule->getIsImportant());
                $this->addRule($oNewRule);
            }
            $this->removeRule($oRule);
            return;
        }
        foreach ($aValues as $mValue) {
            if (!$mValue instanceof Value) {
                $mValue = \mb_strtolower($mValue);
            }
            if ($mValue instanceof Url) {
                $aListProperties['list-style-image'] = $mValue;
            } elseif (\in_array($mValue, $aListStyleTypes, true)) {
                $aListProperties['list-style-types'] = $mValue;
            } elseif (\in_array($mValue, $aListStylePositions, true)) {
                $aListProperties['list-style-position'] = $mValue;
            }
        }
        foreach ($aListProperties as $sProperty => $mValue) {
            $oNewRule = new Rule($sProperty, $oRule->getLineNumber(), $oRule->getColumnNumber());
            $oNewRule->setIsImportant($oRule->getIsImportant());
            $oNewRule->addValue($mValue);
            $this->addRule($oNewRule);
        }
        $this->removeRule($oRule);
    }

    /**
     * @param array<array-key, string> $aProperties
     * @param string $sShorthand
     *
     * @deprecated since 8.7.0, will be removed without substitution in version 9.0 in #511
     */
    public function createShorthandProperties(array $aProperties, $sShorthand): void
    {
        $aRules = $this->getRulesAssoc();
        $oRule = null;
        $aNewValues = [];
        foreach ($aProperties as $sProperty) {
            if (!isset($aRules[$sProperty])) {
                continue;
            }
            $oRule = $aRules[$sProperty];
            if (!$oRule->getIsImportant()) {
                $mRuleValue = $oRule->getValue();
                $aValues = [];
                if (!$mRuleValue instanceof RuleValueList) {
                    $aValues[] = $mRuleValue;
                } else {
                    $aValues = $mRuleValue->getListComponents();
                }
                foreach ($aValues as $mValue) {
                    $aNewValues[] = $mValue;
                }
                $this->removeRule($oRule);
            }
        }
        if ($aNewValues !== [] && $oRule instanceof Rule) {
            $oNewRule = new Rule($sShorthand, $oRule->getLineNumber(), $oRule->getColumnNumber());
            foreach ($aNewValues as $mValue) {
                $oNewRule->addValue($mValue);
            }
            $this->addRule($oNewRule);
        }
    }

    /**
     * @deprecated since 8.7.0, will be removed without substitution in version 9.0 in #511
     */
    public function createBackgroundShorthand(): void
    {
        $aProperties = [
            'background-color',
            'background-image',
            'background-repeat',
            'background-position',
            'background-attachment',
        ];
        $this->createShorthandProperties($aProperties, 'background');
    }

    /**
     * @deprecated since 8.7.0, will be removed without substitution in version 9.0 in #511
     */
    public function createListStyleShorthand(): void
    {
        $aProperties = [
            'list-style-type',
            'list-style-position',
            'list-style-image',
        ];
        $this->createShorthandProperties($aProperties, 'list-style');
    }

    /**
     * Combines `border-color`, `border-style` and `border-width` into `border`.
     *
     * Should be run after `create_dimensions_shorthand`!
     *
     * @deprecated since 8.7.0, will be removed without substitution in version 9.0 in #511
     */
    public function createBorderShorthand(): void
    {
        $aProperties = [
            'border-width',
            'border-style',
            'border-color',
        ];
        $this->createShorthandProperties($aProperties, 'border');
    }

    /**
     * Looks for long format CSS dimensional properties
     * (margin, padding, border-color, border-style and border-width)
     * and converts them into shorthand CSS properties.
     *
     * @deprecated since 8.7.0, will be removed without substitution in version 9.0 in #511
     */
    public function createDimensionsShorthand(): void
    {
        $aPositions = ['top', 'right', 'bottom', 'left'];
        $aExpansions = [
            'margin' => 'margin-%s',
            'padding' => 'padding-%s',
            'border-color' => 'border-%s-color',
            'border-style' => 'border-%s-style',
            'border-width' => 'border-%s-width',
        ];
        $aRules = $this->getRulesAssoc();
        foreach ($aExpansions as $sProperty => $sExpanded) {
            $aFoldable = [];
            foreach ($aRules as $sRuleName => $oRule) {
                foreach ($aPositions as $sPosition) {
                    if ($sRuleName == \sprintf($sExpanded, $sPosition)) {
                        $aFoldable[$sRuleName] = $oRule;
                    }
                }
            }
            // All four dimensions must be present
            if (\count($aFoldable) == 4) {
                $aValues = [];
                foreach ($aPositions as $sPosition) {
                    $oRule = $aRules[\sprintf($sExpanded, $sPosition)];
                    $mRuleValue = $oRule->getValue();
                    $aRuleValues = [];
                    if (!$mRuleValue instanceof RuleValueList) {
                        $aRuleValues[] = $mRuleValue;
                    } else {
                        $aRuleValues = $mRuleValue->getListComponents();
                    }
                    $aValues[$sPosition] = $aRuleValues;
                }
                $oNewRule = new Rule($sProperty, $oRule->getLineNumber(), $oRule->getColumnNumber());
                if ($aValues['left'][0]->render(new OutputFormat()) == $aValues['right'][0]->render(new OutputFormat())) {
                    if ($aValues['top'][0]->render(new OutputFormat()) == $aValues['bottom'][0]->render(new OutputFormat())) {
                        if ($aValues['top'][0]->render(new OutputFormat()) == $aValues['left'][0]->render(new OutputFormat())) {
                            // All 4 sides are equal
                            $oNewRule->addValue($aValues['top']);
                        } else {
                            // Top and bottom are equal, left and right are equal
                            $oNewRule->addValue($aValues['top']);
                            $oNewRule->addValue($aValues['left']);
                        }
                    } else {
                        // Only left and right are equal
                        $oNewRule->addValue($aValues['top']);
                        $oNewRule->addValue($aValues['left']);
                        $oNewRule->addValue($aValues['bottom']);
                    }
                } else {
                    // No sides are equal
                    $oNewRule->addValue($aValues['top']);
                    $oNewRule->addValue($aValues['left']);
                    $oNewRule->addValue($aValues['bottom']);
                    $oNewRule->addValue($aValues['right']);
                }
                $this->addRule($oNewRule);
                foreach ($aPositions as $sPosition) {
                    $this->removeRule($aRules[\sprintf($sExpanded, $sPosition)]);
                }
            }
        }
    }

    /**
     * Looks for long format CSS font properties (e.g. `font-weight`) and
     * tries to convert them into a shorthand CSS `font` property.
     *
     * At least `font-size` AND `font-family` must be present in order to create a shorthand declaration.
     *
     * @deprecated since 8.7.0, will be removed without substitution in version 9.0 in #511
     */
    public function createFontShorthand(): void
    {
        $aFontProperties = [
            'font-style',
            'font-variant',
            'font-weight',
            'font-size',
            'line-height',
            'font-family',
        ];
        $aRules = $this->getRulesAssoc();
        if (!isset($aRules['font-size']) || !isset($aRules['font-family'])) {
            return;
        }
        $oOldRule = $aRules['font-size'] ?? $aRules['font-family'];
        $oNewRule = new Rule('font', $oOldRule->getLineNumber(), $oOldRule->getColumnNumber());
        unset($oOldRule);
        foreach (['font-style', 'font-variant', 'font-weight'] as $sProperty) {
            if (isset($aRules[$sProperty])) {
                $oRule = $aRules[$sProperty];
                $mRuleValue = $oRule->getValue();
                $aValues = [];
                if (!$mRuleValue instanceof RuleValueList) {
                    $aValues[] = $mRuleValue;
                } else {
                    $aValues = $mRuleValue->getListComponents();
                }
                if ($aValues[0] !== 'normal') {
                    $oNewRule->addValue($aValues[0]);
                }
            }
        }
        // Get the font-size value
        $oRule = $aRules['font-size'];
        $mRuleValue = $oRule->getValue();
        $aFSValues = [];
        if (!$mRuleValue instanceof RuleValueList) {
            $aFSValues[] = $mRuleValue;
        } else {
            $aFSValues = $mRuleValue->getListComponents();
        }
        // But wait to know if we have line-height to add it
        if (isset($aRules['line-height'])) {
            $oRule = $aRules['line-height'];
            $mRuleValue = $oRule->getValue();
            $aLHValues = [];
            if (!$mRuleValue instanceof RuleValueList) {
                $aLHValues[] = $mRuleValue;
            } else {
                $aLHValues = $mRuleValue->getListComponents();
            }
            if ($aLHValues[0] !== 'normal') {
                $val = new RuleValueList('/', $this->lineNumber);
                $val->addListComponent($aFSValues[0]);
                $val->addListComponent($aLHValues[0]);
                $oNewRule->addValue($val);
            }
        } else {
            $oNewRule->addValue($aFSValues[0]);
        }
        $oRule = $aRules['font-family'];
        $mRuleValue = $oRule->getValue();
        $aFFValues = [];
        if (!$mRuleValue instanceof RuleValueList) {
            $aFFValues[] = $mRuleValue;
        } else {
            $aFFValues = $mRuleValue->getListComponents();
        }
        $oFFValue = new RuleValueList(',', $this->lineNumber);
        $oFFValue->setListComponents($aFFValues);
        $oNewRule->addValue($oFFValue);

        $this->addRule($oNewRule);
        foreach ($aFontProperties as $sProperty) {
            if (isset($aRules[$sProperty])) {
                $this->removeRule($aRules[$sProperty]);
            }
        }
    }
}
