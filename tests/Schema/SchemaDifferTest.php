<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Tests\Schema;

use MeRezaRezaei\Teleproto\Schema\SchemaDiffer;
use PHPUnit\Framework\TestCase;

class SchemaDifferTest extends TestCase
{
    public function testDiffReportsAddedRemovedChangedAndLayer(): void
    {
        $old = ['layer' => 227, 'methods' => [
            'a.x' => ['params' => [['name' => 'p', 'type' => 'int']], 'return' => 'X', 'errors' => []],
            'b.y' => ['params' => [], 'return' => 'Y', 'errors' => []],
        ]];
        $new = ['layer' => 228, 'methods' => [
            'a.x' => ['params' => [['name' => 'p', 'type' => 'long']], 'return' => 'X', 'errors' => []], // changed
            'b.y' => ['params' => [], 'return' => 'Y', 'errors' => []],                                  // unchanged
            'c.z' => ['params' => [], 'return' => 'Z', 'errors' => []],                                  // added
        ]];
        $d = SchemaDiffer::diff($old, $new);
        $this->assertSame(['c.z'], $d['added']);
        $this->assertSame([], $d['removed']);
        $this->assertSame(['a.x'], $d['changed']);
        $this->assertSame(228, $d['layer']);
    }

    public function testIdenticalArtifactsAreZeroDiff(): void
    {
        $a = ['layer' => 227, 'methods' => ['m' => ['params' => [], 'return' => 'R', 'errors' => ['E']]]];
        $d = SchemaDiffer::diff($a, $a);
        $this->assertSame(['added' => [], 'removed' => [], 'changed' => [], 'layer' => null], $d);
    }

    public function testLayerProvenanceChangeReportsNewLayerOnlyWhenLayerMoves(): void
    {
        // schema layer 229→230, wire(errors) layer stays 227: the artifact
        // `layer` (max) moves 229→230 and must be reported as int 230.
        $old = ['layer' => 229, 'layers' => ['schema' => 229, 'errors' => 227], 'methods' => []];
        $new = ['layer' => 230, 'layers' => ['schema' => 230, 'errors' => 227], 'methods' => []];
        $d = SchemaDiffer::diff($old, $new);
        $this->assertSame([], $d['added']);
        $this->assertSame([], $d['removed']);
        $this->assertSame([], $d['changed']);
        $this->assertSame(230, $d['layer']);

        // wire(errors) layer alone moving also moves the max and is reported
        $new2 = ['layer' => 230, 'layers' => ['schema' => 229, 'errors' => 230], 'methods' => []];
        $this->assertSame(230, SchemaDiffer::diff($old, $new2)['layer']);

        // equal layers (even non-zero) → null regardless of method churn
        $sameLayers = ['layer' => 229, 'layers' => ['schema' => 229, 'errors' => 227], 'methods' => []];
        $this->assertNull(SchemaDiffer::diff($old, $sameLayers)['layer']);

        // bot-http artifacts carry no layer at all → null on both sides
        $botA = ['methods' => ['m' => ['params' => [], 'returns' => ['X']]]];
        $botB = ['methods' => ['m' => ['params' => [['name' => 'q', 'type' => 'int']], 'returns' => ['X']]]];
        $this->assertNull(SchemaDiffer::diff($botA, $botB)['layer']);
    }

    public function testChangedCoversIdParamsReturnRequiredErrorsAndDescription(): void
    {
        $base = ['layer' => 1, 'methods' => ['m' => [
            'id' => '0x00000001', 'params' => [], 'return' => 'X', 'required' => [], 'errors' => [], 'description' => 'd',
        ]]];
        $variants = [
            'id' => ['id' => '0x00000002', 'params' => [], 'return' => 'X', 'required' => [], 'errors' => [], 'description' => 'd'],
            'params' => ['id' => '0x00000001', 'params' => [['name' => 'p', 'type' => 'int']], 'return' => 'X', 'required' => [], 'errors' => [], 'description' => 'd'],
            'return' => ['id' => '0x00000001', 'params' => [], 'return' => 'Y', 'required' => [], 'errors' => [], 'description' => 'd'],
            'required' => ['id' => '0x00000001', 'params' => [], 'return' => 'X', 'required' => ['p'], 'errors' => [], 'description' => 'd'],
            'errors' => ['id' => '0x00000001', 'params' => [], 'return' => 'X', 'required' => [], 'errors' => ['TIMEOUT'], 'description' => 'd'],
            'description' => ['id' => '0x00000001', 'params' => [], 'return' => 'X', 'required' => [], 'errors' => [], 'description' => 'other'],
        ];
        foreach ($variants as $field => $method) {
            $d = SchemaDiffer::diff($base, ['layer' => 1, 'methods' => ['m' => $method]]);
            $this->assertSame(['m'], $d['changed'], "field {$field} change must be detected");
        }
    }

    public function testReportSectionSurfacesBothLayerNumbersAndCapsNamesAtTwenty(): void
    {
        $old = ['layer' => 229, 'layers' => ['schema' => 229, 'errors' => 227], 'methods' => []];
        $methods = [];
        foreach (range(1, 25) as $i) {
            $methods[sprintf('added.%02d', $i)] = ['params' => [], 'return' => 'X', 'errors' => []];
        }
        $new = ['layer' => 230, 'layers' => ['schema' => 230, 'errors' => 227], 'methods' => $methods];

        $section = SchemaDiffer::reportSection('mtproto', $old, $new);

        // both provenance numbers stay visible: schema layer vs wire(errors) layer
        $this->assertStringContainsString('schema 229 → 230', $section);
        $this->assertStringContainsString('errors/wire 227 → 227', $section);
        $this->assertStringContainsString('layer 229 → 230', $section);
        // 25 added names capped at 20 with an "… and N more" tail
        $this->assertStringContainsString('Added: 25', $section);
        $this->assertStringContainsString('added.20', $section);
        $this->assertStringNotContainsString('added.21', $section);
        $this->assertStringContainsString('… and 5 more', $section);
        $this->assertSame(20, substr_count($section, '  - added.'));
    }

    public function testReportSectionCleanWhenIdentical(): void
    {
        $a = ['layer' => 227, 'layers' => ['schema' => 227, 'errors' => 227], 'methods' => ['m' => ['params' => [], 'return' => 'R', 'errors' => []]]];
        $section = SchemaDiffer::reportSection('mtproto', $a, $a);
        $this->assertStringContainsString('## mtproto', $section);
        $this->assertStringContainsString('No differences', $section);
        // provenance stays visible even on a clean report
        $this->assertStringContainsString('schema 227 → 227', $section);
        $this->assertStringContainsString('errors/wire 227 → 227', $section);
    }
}
