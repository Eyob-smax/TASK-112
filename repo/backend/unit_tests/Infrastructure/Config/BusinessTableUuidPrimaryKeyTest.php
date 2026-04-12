<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Business table UUID primary key contract', function () {

    /**
     * Prompt-listed business tables that must use UUID primary identifiers.
     */
    $businessTables = [
        'users',
        'roles',
        'departments',
        'documents',
        'document_versions',
        'attachments',
        'attachment_links',
        'configuration_sets',
        'configuration_versions',
        'workflow_templates',
        'workflow_instances',
        'workflow_nodes',
        'approvals',
        'to_do_items',
        'sales_documents',
        'sales_line_items',
        'returns',
        'inventory_movements',
    ];

    it('uses UUID id columns for all prompt-listed business tables', function () use ($businessTables) {
        foreach ($businessTables as $table) {
            $column = DB::selectOne("SHOW COLUMNS FROM {$table} LIKE 'id'");

            expect($column)->not->toBeNull("Table '{$table}' must contain an 'id' column");

            $type = strtolower((string) ($column->Type ?? ''));

            expect($type)->toContain('char(36)');
            expect(strtolower((string) ($column->Null ?? 'yes')))->toBe('no', "Table '{$table}' id must be NOT NULL");
        }
    });
});
