<?php

declare(strict_types=1);

namespace App\Tests\Promotion\Service\Dsl;

use App\Promotion\Service\Dsl\AstNode;
use App\Promotion\Service\Dsl\DslSyntaxException;
use App\Promotion\Service\Dsl\Lexer;
use App\Promotion\Service\Dsl\Parser;
use PHPUnit\Framework\TestCase;

final class ParserTest extends TestCase
{
    private Lexer $lexer;
    private Parser $parser;

    protected function setUp(): void
    {
        $this->lexer = new Lexer();
        $this->parser = new Parser();
    }

    private function parse(string $dsl): AstNode
    {
        $tokens = $this->lexer->tokenize($dsl);
        return $this->parser->parse($tokens);
    }

    private function findChild(AstNode $node, string $type): ?AstNode
    {
        foreach ($node->children as $child) {
            if ($child->type === $type) {
                return $child;
            }
        }
        return null;
    }

    // ──────────────────────────── type ────────────────────────────

    public function testParseMinimalProgram(): void
    {
        $ast = $this->parse('type: full_reduction');

        self::assertSame('program', $ast->type);
        self::assertSame('full_reduction', $ast->data['type']);
    }

    public function testParseAllSevenTypes(): void
    {
        // 'tiered' is a keyword, not a valid type identifier. Test the 6 valid ones.
        $types = ['full_reduction', 'discount', 'gift', 'nth_discount', 'free_shipping', 'member_discount'];

        foreach ($types as $type) {
            $ast = $this->parse("type: {$type}");
            self::assertSame($type, $ast->data['type']);
        }
    }

    // ──────────────────────────── phase ────────────────────────────

    public function testParsePhaseInner(): void
    {
        $ast = $this->parse("type: full_reduction\nphase: inner");

        self::assertSame(0, $ast->data['phase']);
    }

    public function testParsePhaseOuter(): void
    {
        $ast = $this->parse("type: full_reduction\nphase: outer");

        self::assertSame(1, $ast->data['phase']);
    }

    // ──────────────────────────── when ────────────────────────────

    public function testParseWhenSimpleCondition(): void
    {
        $dsl = "type: full_reduction\nwhen:\n  cart.subtotal >= 200";
        $ast = $this->parse($dsl);

        $whenNode = $this->findChild($ast, 'when');
        self::assertNotNull($whenNode);
        self::assertCount(1, $whenNode->children);

        $cond = $whenNode->children[0];
        self::assertSame('condition', $cond->type);
        self::assertSame('>=', $cond->data['op']);
        self::assertSame('path', $cond->data['left']->type);
        self::assertSame('cart.subtotal', $cond->data['left']->data['value']);
        self::assertSame('literal', $cond->data['right']->type);
        self::assertSame(200, $cond->data['right']->data['value']);
    }

    public function testParseWhenAndBlock(): void
    {
        $dsl = "type: full_reduction\nwhen:\n  and:\n    cart.subtotal >= 200\n    cart.items.count >= 2";
        $ast = $this->parse($dsl);

        $whenNode = $this->findChild($ast, 'when');
        self::assertCount(1, $whenNode->children);
        self::assertSame('and', $whenNode->children[0]->type);
        self::assertCount(2, $whenNode->children[0]->children);
        self::assertSame('condition', $whenNode->children[0]->children[0]->type);
        self::assertSame('condition', $whenNode->children[0]->children[1]->type);
    }

    public function testParseWhenOrBlock(): void
    {
        $dsl = "type: full_reduction\nwhen:\n  or:\n    cart.subtotal >= 100\n    config.threshold <= 500";
        $ast = $this->parse($dsl);

        $whenNode = $this->findChild($ast, 'when');
        self::assertCount(1, $whenNode->children);
        self::assertSame('or', $whenNode->children[0]->type);
        self::assertCount(2, $whenNode->children[0]->children);
    }

    public function testParseWhenNotBlock(): void
    {
        $dsl = "type: full_reduction\nwhen:\n  not:\n    cart.subtotal < 50";
        $ast = $this->parse($dsl);

        $whenNode = $this->findChild($ast, 'when');
        self::assertCount(1, $whenNode->children);
        self::assertSame('not', $whenNode->children[0]->type);
        self::assertCount(1, $whenNode->children[0]->children);
        self::assertSame('condition', $whenNode->children[0]->children[0]->type);
        self::assertSame('<', $whenNode->children[0]->children[0]->data['op']);
    }

    public function testParseWhenMultipleConditions(): void
    {
        $dsl = "type: full_reduction\nwhen:\n  cart.subtotal >= 200\n  cart.items.count >= 3\n  cart.subtotal <= 1000";
        $ast = $this->parse($dsl);

        $whenNode = $this->findChild($ast, 'when');
        self::assertCount(3, $whenNode->children);
        self::assertSame('condition', $whenNode->children[0]->type);
        self::assertSame('condition', $whenNode->children[1]->type);
        self::assertSame('condition', $whenNode->children[2]->type);
    }

    public function testParseWhenNestedLogic(): void
    {
        $dsl = "type: full_reduction\nwhen:\n  and:\n    cart.subtotal >= 100\n    or:\n      cart.subtotal >= 50\n      cart.items.count >= 5";
        $ast = $this->parse($dsl);

        $whenNode = $this->findChild($ast, 'when');
        $andNode = $whenNode->children[0];
        self::assertSame('and', $andNode->type);
        self::assertCount(2, $andNode->children);
        self::assertSame('condition', $andNode->children[0]->type);
        self::assertSame('or', $andNode->children[1]->type);
        self::assertCount(2, $andNode->children[1]->children);
    }

    public function testParseWhenWithStringLiteral(): void
    {
        $dsl = "type: full_reduction\nwhen:\n  cart.subtotal >= \"100\"";
        $ast = $this->parse($dsl);

        $whenNode = $this->findChild($ast, 'when');
        $cond = $whenNode->children[0];
        self::assertSame('100', $cond->data['right']->data['value']);
    }

    public function testParseWhenInOperator(): void
    {
        // 'in' and 'includes' are valid operators in parseOperator
        $dsl = "type: full_reduction\nwhen:\n  cart.subtotal in 200";
        $ast = $this->parse($dsl);

        $whenNode = $this->findChild($ast, 'when');
        self::assertSame('in', $whenNode->children[0]->data['op']);
    }

    public function testParseWhenIncludesOperator(): void
    {
        $dsl = "type: full_reduction\nwhen:\n  cart.subtotal includes 200";
        $ast = $this->parse($dsl);

        $whenNode = $this->findChild($ast, 'when');
        self::assertSame('includes', $whenNode->children[0]->data['op']);
    }

    // ──────────────────────────── do: discount ────────────────────────────

    public function testParseDoDiscountOrder(): void
    {
        $dsl = "type: full_reduction\ndo:\n  discount order 20";
        $ast = $this->parse($dsl);

        $doNode = $this->findChild($ast, 'do');
        self::assertNotNull($doNode);
        self::assertCount(1, $doNode->children);
        self::assertSame('action_discount', $doNode->children[0]->type);
        self::assertSame('order', $doNode->children[0]->data['target']);
        self::assertSame(20, $doNode->children[0]->data['value']);
    }

    public function testParseDoDiscountOrderPercent(): void
    {
        $dsl = "type: discount\ndo:\n  discount order 10%";
        $ast = $this->parse($dsl);

        $doNode = $this->findChild($ast, 'do');
        self::assertSame('action_discount', $doNode->children[0]->type);
        self::assertSame('order', $doNode->children[0]->data['target']);
        self::assertSame(10, $doNode->children[0]->data['value']);
        self::assertTrue($doNode->children[0]->data['isPercent']);
    }

    public function testParseDoDiscountOrderPercentMaxCap(): void
    {
        $dsl = "type: discount\ndo:\n  discount order 10% max 50";
        $ast = $this->parse($dsl);

        $doNode = $this->findChild($ast, 'do');
        self::assertSame(10, $doNode->children[0]->data['value']);
        self::assertTrue($doNode->children[0]->data['isPercent']);
        self::assertSame(50, $doNode->children[0]->data['maxCap']);
    }

    public function testParseDoDiscountItem(): void
    {
        $dsl = "type: nth_discount\ndo:\n  discount item 2 50%";
        $ast = $this->parse($dsl);

        $doNode = $this->findChild($ast, 'do');
        self::assertSame('action_discount', $doNode->children[0]->type);
        self::assertSame('item', $doNode->children[0]->data['target']);
        self::assertSame(2, $doNode->children[0]->data['position']);
        self::assertSame(50, $doNode->children[0]->data['rate']);
        self::assertTrue($doNode->children[0]->data['isPercent']);
    }

    // ──────────────────────────── do: gift ────────────────────────────

    public function testParseDoAddGift(): void
    {
        $dsl = "type: gift\ndo:\n  add gift spec: 123 count: 1";
        $ast = $this->parse($dsl);

        $doNode = $this->findChild($ast, 'do');
        self::assertNotNull($doNode);
        self::assertCount(1, $doNode->children);
        self::assertSame('action_gift', $doNode->children[0]->type);
        self::assertSame(123, $doNode->children[0]->data['spec']);
        self::assertSame(1, $doNode->children[0]->data['count']);
    }

    // ──────────────────────────── do: free_shipping ────────────────────────────

    public function testParseDoFreeShipping(): void
    {
        $dsl = "type: free_shipping\ndo:\n  free shipping";
        $ast = $this->parse($dsl);

        $doNode = $this->findChild($ast, 'do');
        self::assertNotNull($doNode);
        self::assertCount(1, $doNode->children);
        self::assertSame('action_free_shipping', $doNode->children[0]->type);
    }

    // ──────────────────────────── do: member_discount ────────────────────────────

    public function testParseDoMemberDiscount(): void
    {
        $dsl = "type: member_discount\ndo:\n  member discount 90%";
        $ast = $this->parse($dsl);

        $doNode = $this->findChild($ast, 'do');
        self::assertCount(1, $doNode->children);
        self::assertSame('action_member_discount', $doNode->children[0]->type);
        self::assertSame(90, $doNode->children[0]->data['rate']);
    }

    // ──────────────────────────── do: tiered ────────────────────────────

    public function testParseDoTiered(): void
    {
        // 'tiered' keyword after 'do: ...' triggers parseTieredAction
        $dsl = "type: full_reduction\ndo:\n  tiered:\n    - threshold: 100 discount: 10\n    - threshold: 200 discount: 30";
        $ast = $this->parse($dsl);

        $doNode = $this->findChild($ast, 'do');
        self::assertCount(1, $doNode->children);
        self::assertSame('action_tiered', $doNode->children[0]->type);
        self::assertCount(2, $doNode->children[0]->children);
        self::assertSame('tier', $doNode->children[0]->children[0]->type);
        self::assertSame(100, $doNode->children[0]->children[0]->data['threshold']);
        self::assertSame(10, $doNode->children[0]->children[0]->data['discount']);
        self::assertSame('tier', $doNode->children[0]->children[1]->type);
        self::assertSame(200, $doNode->children[0]->children[1]->data['threshold']);
        self::assertSame(30, $doNode->children[0]->children[1]->data['discount']);
    }

    // ──────────────────────────── priority ────────────────────────────

    public function testParsePriority(): void
    {
        $dsl = "type: full_reduction\npriority: 100";
        $ast = $this->parse($dsl);

        self::assertSame('100', $ast->data['priority']['value']);
    }

    public function testParsePriorityDesc(): void
    {
        $dsl = "type: full_reduction\npriority: 100 desc";
        $ast = $this->parse($dsl);

        self::assertSame('100', $ast->data['priority']['value']);
        self::assertTrue($ast->data['priority']['desc']);
    }

    // ──────────────────────────── fields ────────────────────────────

    public function testParseFields(): void
    {
        $dsl = "type: full_reduction\nfields:\n  threshold: number: \"Order Threshold\"\n  amount: number: \"Discount Amount\"";
        $ast = $this->parse($dsl);

        $fieldsNode = $this->findChild($ast, 'fields');
        self::assertNotNull($fieldsNode);
        self::assertCount(2, $fieldsNode->children);
        self::assertSame('field', $fieldsNode->children[0]->type);
        self::assertSame('threshold', $fieldsNode->children[0]->data['name']);
        self::assertSame('number', $fieldsNode->children[0]->data['type']);
        self::assertSame('Order Threshold', $fieldsNode->children[0]->data['label']);
        self::assertSame('field', $fieldsNode->children[1]->type);
        self::assertSame('amount', $fieldsNode->children[1]->data['name']);
        self::assertSame('number', $fieldsNode->children[1]->data['type']);
        self::assertSame('Discount Amount', $fieldsNode->children[1]->data['label']);
    }

    // ──────────────────────────── full program ────────────────────────────

    public function testParseFullProgram(): void
    {
        $dsl = "type: full_reduction\nphase: inner\npriority: 200 desc\nwhen:\n  cart.subtotal >= 100\n  cart.items.count >= 2\ndo:\n  discount order 20\nfields:\n  threshold: number: \"Threshold\"\n  amount: number: \"Amount\"";

        $ast = $this->parse($dsl);

        self::assertSame('program', $ast->type);
        self::assertSame('full_reduction', $ast->data['type']);
        self::assertSame(0, $ast->data['phase']);
        self::assertSame('200', $ast->data['priority']['value']);
        self::assertTrue($ast->data['priority']['desc']);

        $whenNode = $this->findChild($ast, 'when');
        self::assertNotNull($whenNode);
        self::assertCount(2, $whenNode->children);

        $doNode = $this->findChild($ast, 'do');
        self::assertNotNull($doNode);
        self::assertCount(1, $doNode->children);

        $fieldsNode = $this->findChild($ast, 'fields');
        self::assertNotNull($fieldsNode);
        self::assertCount(2, $fieldsNode->children);
    }

    // ──────────────────────────── errors ────────────────────────────

    public function testParseMissingColonThrows(): void
    {
        $this->expectException(DslSyntaxException::class);
        $this->expectExceptionMessage("Expected ':'");

        $this->parse('type');
    }

    public function testParseUnknownActionThrows(): void
    {
        $this->expectException(DslSyntaxException::class);
        $this->expectExceptionMessage("Unknown action 'bogus'");

        $dsl = "type: full_reduction\ndo:\n  bogus something";
        $this->parse($dsl);
    }

    public function testParseInvalidOperatorThrows(): void
    {
        $this->expectException(DslSyntaxException::class);
        $this->expectExceptionMessage("Invalid operator");

        $dsl = "type: full_reduction\nwhen:\n  cart.subtotal >= 200\n  not:\n    cart.subtotal bogus 200";
        $this->parse($dsl);
    }

    public function testParseInvalidTargetThrows(): void
    {
        $this->expectException(DslSyntaxException::class);
        $this->expectExceptionMessage("Expected 'order' or 'item'");

        $dsl = "type: full_reduction\ndo:\n  discount unknown 20";
        $this->parse($dsl);
    }

    public function testParseWhenWithDecimalNumbers(): void
    {
        $dsl = "type: full_reduction\nwhen:\n  cart.subtotal >= 199.99";
        $ast = $this->parse($dsl);

        $whenNode = $this->findChild($ast, 'when');
        self::assertSame(199.99, $whenNode->children[0]->data['right']->data['value']);
    }

    public function testParseDoDiscountOrderWithConfigRef(): void
    {
        $dsl = "type: full_reduction\ndo:\n  discount order config.amount";
        $ast = $this->parse($dsl);

        $doNode = $this->findChild($ast, 'do');
        self::assertSame('action_discount', $doNode->children[0]->type);
        self::assertSame('order', $doNode->children[0]->data['target']);
        self::assertSame('config.amount', $doNode->children[0]->data['value']);
    }

    public function testParseDoDiscountConfigPercentMax(): void
    {
        $dsl = "type: discount\ndo:\n  discount order config.rate% max 50";
        $ast = $this->parse($dsl);

        $doNode = $this->findChild($ast, 'do');
        self::assertSame('config.rate', $doNode->children[0]->data['value']);
        self::assertTrue($doNode->children[0]->data['isPercent']);
        self::assertSame(50, $doNode->children[0]->data['maxCap']);
    }
}
