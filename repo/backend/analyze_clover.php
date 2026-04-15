<?php
// Merge API + Unit clover reports and compute per-file coverage.
$apiXml = simplexml_load_file('/tmp/api.clover.xml');
$unitXml = simplexml_load_file('/tmp/unit.clover.xml');

$merged = [];
foreach ([$apiXml, $unitXml] as $report) {
    foreach ($report->project->file as $file) {
        $path = (string)$file['name'];
        if (!isset($merged[$path])) {
            $merged[$path] = ['lines' => []];
        }
        foreach ($file->line as $line) {
            if ((string)$line['type'] !== 'stmt') continue;
            $num = (int)$line['num'];
            $count = (int)$line['count'];
            if (!isset($merged[$path]['lines'][$num])) {
                $merged[$path]['lines'][$num] = $count;
            } else {
                $merged[$path]['lines'][$num] = max($merged[$path]['lines'][$num], $count);
            }
        }
    }
}

$totalAll = 0; $coveredAll = 0;
$rows = [];
foreach ($merged as $path => $data) {
    $total = count($data['lines']);
    $covered = 0;
    foreach ($data['lines'] as $count) if ($count > 0) $covered++;
    $uncovered = $total - $covered;
    $pct = $total === 0 ? 100.0 : ($covered / $total) * 100.0;
    $rows[] = [
        'path' => $path,
        'total' => $total,
        'covered' => $covered,
        'uncovered' => $uncovered,
        'pct' => $pct,
    ];
    $totalAll += $total;
    $coveredAll += $covered;
}
usort($rows, fn($a,$b)=> $b['uncovered'] <=> $a['uncovered']);
echo "=== Top 50 files by absolute uncovered lines (merged API+Unit) ===\n";
printf("%-60s %6s %6s %6s %6s\n", "File", "Total", "Cov", "Uncov", "Pct");
foreach (array_slice($rows,0,50) as $r) {
    printf("%-60s %6d %6d %6d %6.2f%%\n", basename($r['path']), $r['total'], $r['covered'], $r['uncovered'], $r['pct']);
}
echo "\n=== OVERALL merged ===\n";
printf("Lines: %d/%d = %.2f%%\n", $coveredAll, $totalAll, $totalAll===0?0:($coveredAll/$totalAll)*100);
echo "\n=== Files at 0% coverage ===\n";
$zero = array_filter($rows, fn($r) => $r['covered'] === 0 && $r['total'] > 0);
foreach ($zero as $r) {
    printf("%-60s %6d lines\n", $r['path'], $r['total']);
}
