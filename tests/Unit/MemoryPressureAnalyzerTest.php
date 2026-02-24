<?php

declare(strict_types=1);

namespace QuerySentinel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use QuerySentinel\Analyzers\MemoryPressureAnalyzer;
use QuerySentinel\Enums\ComplexityClass;
use QuerySentinel\Enums\Severity;
use QuerySentinel\Support\EnvironmentContext;
use QuerySentinel\Support\ExecutionProfile;

final class MemoryPressureAnalyzerTest extends TestCase
{
    private MemoryPressureAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->analyzer = new MemoryPressureAnalyzer;
    }

    // ---------------------------------------------------------------
    // Helper to build an ExecutionProfile with defaults
    // ---------------------------------------------------------------

    private function makeProfile(int $logicalReads = 0, int $physicalReads = 0): ExecutionProfile
    {
        return new ExecutionProfile(
            nestedLoopDepth: 1,
            joinFanouts: [],
            btreeDepths: [],
            logicalReads: $logicalReads,
            physicalReads: $physicalReads,
            scanComplexity: ComplexityClass::Linear,
            sortComplexity: ComplexityClass::Constant,
        );
    }

    private function makeEnvironment(
        int $bufferPoolSizeBytes = 134217728,
        int $innodbPageSize = 16384,
        int $tmpTableSize = 16777216,
    ): EnvironmentContext {
        return new EnvironmentContext(
            mysqlVersion: '8.0.35',
            bufferPoolSizeBytes: $bufferPoolSizeBytes,
            innodbIoCapacity: 200,
            innodbPageSize: $innodbPageSize,
            tmpTableSize: $tmpTableSize,
            maxHeapTableSize: $tmpTableSize,
            bufferPoolUtilization: 0.75,
            isColdCache: false,
            databaseName: 'test_db',
        );
    }

    // ---------------------------------------------------------------
    // 1. Simple query - low memory
    // ---------------------------------------------------------------

    public function test_simple_query_low_memory(): void
    {
        $metrics = [
            'rows_examined' => 100,
            'has_filesort' => false,
            'has_temp_table' => false,
            'has_disk_temp' => false,
            'join_count' => 0,
        ];

        $result = $this->analyzer->analyze($metrics, null, null);

        $this->assertSame('low', $result['memory_pressure']['memory_risk']);
        $this->assertSame(0, $result['memory_pressure']['components']['sort_buffer_bytes']);
        $this->assertSame(0, $result['memory_pressure']['components']['join_buffer_bytes']);
        $this->assertSame(0, $result['memory_pressure']['components']['temp_table_bytes']);
        $this->assertSame(0, $result['memory_pressure']['components']['disk_spill_bytes']);
        // Page estimation: ceil(100 * 256 / 16384) = 2 pages = 32KB (realistic working set)
        $this->assertSame(32768, $result['memory_pressure']['components']['buffer_pool_reads_bytes']);
        $this->assertSame(32768, $result['memory_pressure']['total_estimated_bytes']);
        $this->assertEmpty($result['findings']);
    }

    // ---------------------------------------------------------------
    // 2. Filesort allocates sort buffer
    // ---------------------------------------------------------------

    public function test_filesort_allocates_sort_buffer(): void
    {
        $metrics = [
            'rows_examined' => 5000,
            'has_filesort' => true,
            'has_temp_table' => false,
            'has_disk_temp' => false,
            'join_count' => 0,
        ];

        $result = $this->analyzer->analyze($metrics, null, null);

        $sortBuffer = $result['memory_pressure']['components']['sort_buffer_bytes'];
        $this->assertGreaterThan(0, $sortBuffer);
        // 5000 rows * 256 bytes = 1,280,000 but capped at sort_buffer_size (262144)
        $this->assertSame(262144, $sortBuffer);
    }

    // ---------------------------------------------------------------
    // 3. Sort buffer capped at buffer size
    // ---------------------------------------------------------------

    public function test_sort_buffer_capped_at_buffer_size(): void
    {
        $metrics = [
            'rows_examined' => 1000000, // huge number
            'has_filesort' => true,
            'has_temp_table' => false,
            'has_disk_temp' => false,
            'join_count' => 0,
        ];

        $result = $this->analyzer->analyze($metrics, null, null);

        // min(262144, 1000000 * 256) = 262144 (capped at sort_buffer_size)
        $sortBuffer = $result['memory_pressure']['components']['sort_buffer_bytes'];
        $this->assertSame(262144, $sortBuffer);
    }

    // ---------------------------------------------------------------
    // 4. Join buffers scale with join count
    // ---------------------------------------------------------------

    public function test_join_buffers_scale_with_join_count(): void
    {
        $metrics = [
            'rows_examined' => 100,
            'has_filesort' => false,
            'has_temp_table' => false,
            'has_disk_temp' => false,
            'join_count' => 3,
        ];

        $result = $this->analyzer->analyze($metrics, null, null);

        // (3 - 1) * 262144 = 524288
        $joinBuffer = $result['memory_pressure']['components']['join_buffer_bytes'];
        $this->assertSame(524288, $joinBuffer);
    }

    // ---------------------------------------------------------------
    // 5. Temp table allocates memory
    // ---------------------------------------------------------------

    public function test_temp_table_allocates_memory(): void
    {
        $metrics = [
            'rows_examined' => 10000,
            'has_filesort' => false,
            'has_temp_table' => true,
            'has_disk_temp' => false,
            'join_count' => 0,
        ];

        $result = $this->analyzer->analyze($metrics, null, null);

        $tempTable = $result['memory_pressure']['components']['temp_table_bytes'];
        $this->assertGreaterThan(0, $tempTable);
        // min(16777216, 10000 * 256) = min(16777216, 2560000) = 2560000
        $this->assertSame(2560000, $tempTable);
    }

    // ---------------------------------------------------------------
    // 6. Disk spill detected
    // ---------------------------------------------------------------

    public function test_disk_spill_detected(): void
    {
        $metrics = [
            'rows_examined' => 1000,
            'has_filesort' => false,
            'has_temp_table' => false,
            'has_disk_temp' => true,
            'join_count' => 0,
        ];

        $result = $this->analyzer->analyze($metrics, null, null);

        $diskSpill = $result['memory_pressure']['components']['disk_spill_bytes'];
        $this->assertGreaterThan(0, $diskSpill);
        // 1000 * 256 = 256000
        $this->assertSame(256000, $diskSpill);

        // Should generate a disk spill finding
        $diskFindings = array_filter($result['findings'], fn ($f) => $f->title === 'Disk-based temporary table spill');
        $this->assertNotEmpty($diskFindings);
        $diskFinding = array_values($diskFindings)[0];
        $this->assertSame(Severity::Warning, $diskFinding->severity);
        $this->assertSame('memory_pressure', $diskFinding->category);
    }

    // ---------------------------------------------------------------
    // 7. Buffer pool pressure calculated
    // ---------------------------------------------------------------

    public function test_buffer_pool_pressure_calculated(): void
    {
        $metrics = [
            'rows_examined' => 100,
            'has_filesort' => false,
            'has_temp_table' => false,
            'has_disk_temp' => false,
            'join_count' => 0,
        ];

        $environment = $this->makeEnvironment(
            bufferPoolSizeBytes: 1073741824, // 1GB
            innodbPageSize: 16384,
        );

        // 1000 physical reads * 16384 page size = 16,384,000 bytes
        // pressure = 16384000 / 1073741824 = ~0.0153
        $profile = $this->makeProfile(physicalReads: 1000);

        $result = $this->analyzer->analyze($metrics, $environment, $profile);

        $bufferPoolReads = $result['memory_pressure']['components']['buffer_pool_reads_bytes'];
        $this->assertSame(1000 * 16384, $bufferPoolReads);

        $pressure = $result['memory_pressure']['buffer_pool_pressure'];
        $expected = round((1000 * 16384) / 1073741824, 4);
        $this->assertSame($expected, $pressure);
    }

    // ---------------------------------------------------------------
    // 8. High memory risk classification
    // ---------------------------------------------------------------

    public function test_high_memory_risk_classification(): void
    {
        // Need total > 256MB (268435456 bytes)
        // Use disk spill with enough rows: 268435456 / 256 = 1048576 rows + some extra
        $metrics = [
            'rows_examined' => 1100000, // 1.1M rows * 256 = 281,600,000 > 256MB
            'has_filesort' => false,
            'has_temp_table' => false,
            'has_disk_temp' => true,
            'join_count' => 0,
        ];

        $result = $this->analyzer->analyze($metrics, null, null);

        $this->assertSame('high', $result['memory_pressure']['memory_risk']);
        $this->assertGreaterThan(268435456, $result['memory_pressure']['total_estimated_bytes']);

        // Should have a high memory pressure finding
        $highFindings = array_filter($result['findings'], fn ($f) => str_contains($f->title, 'High memory pressure'));
        $this->assertNotEmpty($highFindings);
        $highFinding = array_values($highFindings)[0];
        $this->assertSame(Severity::Warning, $highFinding->severity);
        $this->assertSame('high', $highFinding->metadata['memory_risk']);
    }

    // ---------------------------------------------------------------
    // 9. Moderate memory risk classification
    // ---------------------------------------------------------------

    public function test_moderate_memory_risk_classification(): void
    {
        // Need total between 64MB (67108864) and 256MB (268435456) with pressure < 0.5.
        // Use 260K rows disk spill with 256MB buffer pool to keep pressure under 0.5.
        // disk_spill = 260000 * 256 = 66,560,000. pages = ceil(66560000/16384) = 4063.
        // buffer_pool_reads = 4063 * 16384 = 66,568,192. total = 133,128,192 (~127MB) → moderate.
        // pressure = 66568192 / 268435456 = 0.248 → moderate (< 0.5).
        $metrics = [
            'rows_examined' => 260000,
            'has_filesort' => false,
            'has_temp_table' => false,
            'has_disk_temp' => true,
            'join_count' => 0,
        ];

        $environment = $this->makeEnvironment(bufferPoolSizeBytes: 268435456); // 256MB pool

        $result = $this->analyzer->analyze($metrics, $environment, null);

        $total = $result['memory_pressure']['total_estimated_bytes'];
        $this->assertGreaterThan(67108864, $total);
        $this->assertLessThanOrEqual(268435456, $total);
        $this->assertSame('moderate', $result['memory_pressure']['memory_risk']);

        // Should have a moderate memory pressure finding
        $modFindings = array_filter($result['findings'], fn ($f) => str_contains($f->title, 'Moderate memory pressure'));
        $this->assertNotEmpty($modFindings);
        $modFinding = array_values($modFindings)[0];
        $this->assertSame(Severity::Optimization, $modFinding->severity);
        $this->assertSame('moderate', $modFinding->metadata['memory_risk']);
    }

    // ---------------------------------------------------------------
    // 10. Low memory risk classification
    // ---------------------------------------------------------------

    public function test_low_memory_risk_classification(): void
    {
        $metrics = [
            'rows_examined' => 100,
            'has_filesort' => true,
            'has_temp_table' => true,
            'has_disk_temp' => false,
            'join_count' => 1,
        ];

        $result = $this->analyzer->analyze($metrics, null, null);

        $total = $result['memory_pressure']['total_estimated_bytes'];
        $this->assertLessThan(67108864, $total);
        $this->assertSame('low', $result['memory_pressure']['memory_risk']);
        // No memory pressure findings for low risk (disk spill findings are separate)
        $memoryFindings = array_filter($result['findings'], fn ($f) => str_contains($f->title, 'memory pressure'));
        $this->assertEmpty($memoryFindings);
    }

    // ---------------------------------------------------------------
    // 11. High buffer pool pressure triggers high risk
    // ---------------------------------------------------------------

    public function test_high_buffer_pool_pressure_is_high_risk(): void
    {
        $metrics = [
            'rows_examined' => 10,
            'has_filesort' => false,
            'has_temp_table' => false,
            'has_disk_temp' => false,
            'join_count' => 0,
        ];

        // pressure > 0.5 → high risk even if total bytes are small
        // physicalReads * pageSize / poolSize > 0.5
        // Use small pool: 1MB, reads that exceed half of it
        $environment = $this->makeEnvironment(
            bufferPoolSizeBytes: 1048576, // 1MB
            innodbPageSize: 16384,
        );

        // 50 physical reads * 16384 = 819200, pressure = 819200/1048576 = 0.78125
        $profile = $this->makeProfile(physicalReads: 50);

        $result = $this->analyzer->analyze($metrics, $environment, $profile);

        $this->assertGreaterThan(0.5, $result['memory_pressure']['buffer_pool_pressure']);
        $this->assertSame('high', $result['memory_pressure']['memory_risk']);
    }

    // ---------------------------------------------------------------
    // 12. Moderate buffer pool pressure
    // ---------------------------------------------------------------

    public function test_moderate_buffer_pool_pressure(): void
    {
        $metrics = [
            'rows_examined' => 10,
            'has_filesort' => false,
            'has_temp_table' => false,
            'has_disk_temp' => false,
            'join_count' => 0,
        ];

        // pressure between 0.2 and 0.5 → moderate
        // Use pool of 1MB, physical reads to get ~0.3 pressure
        // 20 physical reads * 16384 = 327680, pressure = 327680/1048576 = 0.3125
        $environment = $this->makeEnvironment(
            bufferPoolSizeBytes: 1048576, // 1MB
            innodbPageSize: 16384,
        );

        $profile = $this->makeProfile(physicalReads: 20);

        $result = $this->analyzer->analyze($metrics, $environment, $profile);

        $pressure = $result['memory_pressure']['buffer_pool_pressure'];
        $this->assertGreaterThan(0.2, $pressure);
        $this->assertLessThanOrEqual(0.5, $pressure);
        $this->assertSame('moderate', $result['memory_pressure']['memory_risk']);
    }

    // ---------------------------------------------------------------
    // 13. Recommendations generated for disk spill
    // ---------------------------------------------------------------

    public function test_recommendations_generated_for_disk_spill(): void
    {
        $metrics = [
            'rows_examined' => 1000,
            'has_filesort' => false,
            'has_temp_table' => false,
            'has_disk_temp' => true,
            'join_count' => 0,
        ];

        $result = $this->analyzer->analyze($metrics, null, null);

        $recommendations = $result['memory_pressure']['recommendations'];
        $this->assertNotEmpty($recommendations);

        $found = false;
        foreach ($recommendations as $rec) {
            if (str_contains($rec, 'tmp_table_size') && str_contains($rec, 'max_heap_table_size')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Expected recommendation about tmp_table_size and max_heap_table_size');
    }

    // ---------------------------------------------------------------
    // 14. Recommendations for high buffer pool pressure
    // ---------------------------------------------------------------

    public function test_recommendations_for_high_pressure(): void
    {
        $metrics = [
            'rows_examined' => 10,
            'has_filesort' => false,
            'has_temp_table' => false,
            'has_disk_temp' => false,
            'join_count' => 0,
        ];

        $environment = $this->makeEnvironment(
            bufferPoolSizeBytes: 1048576, // 1MB
            innodbPageSize: 16384,
        );

        // 50 physical reads * 16384 = 819200, pressure = 0.78125 > 0.5
        $profile = $this->makeProfile(physicalReads: 50);

        $result = $this->analyzer->analyze($metrics, $environment, $profile);

        $recommendations = $result['memory_pressure']['recommendations'];
        $found = false;
        foreach ($recommendations as $rec) {
            if (str_contains($rec, 'innodb_buffer_pool_size')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Expected recommendation about innodb_buffer_pool_size');
    }

    // ---------------------------------------------------------------
    // 15. Null environment uses defaults
    // ---------------------------------------------------------------

    public function test_null_environment_uses_defaults(): void
    {
        $metrics = [
            'rows_examined' => 1000,
            'has_filesort' => true,
            'has_temp_table' => true,
            'has_disk_temp' => false,
            'join_count' => 2,
        ];

        // Should not throw, should use default values
        $result = $this->analyzer->analyze($metrics, null, null);

        $this->assertArrayHasKey('memory_pressure', $result);
        $this->assertArrayHasKey('findings', $result);
        $this->assertArrayHasKey('components', $result['memory_pressure']);
        $this->assertArrayHasKey('total_estimated_bytes', $result['memory_pressure']);
        $this->assertArrayHasKey('buffer_pool_pressure', $result['memory_pressure']);
        $this->assertArrayHasKey('memory_risk', $result['memory_pressure']);

        // Sort buffer should be populated (has_filesort=true)
        $this->assertGreaterThan(0, $result['memory_pressure']['components']['sort_buffer_bytes']);
        // Join buffer should be populated (join_count=2, so 1 buffer)
        $this->assertSame(262144, $result['memory_pressure']['components']['join_buffer_bytes']);
        // Temp table should be populated
        $this->assertGreaterThan(0, $result['memory_pressure']['components']['temp_table_bytes']);
        // No profile → page estimation fallback: ceil(1000 * 256 / 16384) = 16 pages = 262144 bytes
        $this->assertSame(262144, $result['memory_pressure']['components']['buffer_pool_reads_bytes']);
    }

    // ---------------------------------------------------------------
    // 16. Null profile uses zero reads
    // ---------------------------------------------------------------

    public function test_null_profile_uses_page_estimation(): void
    {
        $metrics = [
            'rows_examined' => 100,
            'has_filesort' => false,
            'has_temp_table' => false,
            'has_disk_temp' => false,
            'join_count' => 0,
        ];

        $result = $this->analyzer->analyze($metrics, null, null);

        // No profile → page estimation: ceil(100 * 256 / 16384) = 2 pages = 32768 bytes
        $this->assertSame(32768, $result['memory_pressure']['components']['buffer_pool_reads_bytes']);
        // pressure = 32768 / 134217728 (128MB default) ≈ 0.0002
        $this->assertLessThan(0.01, $result['memory_pressure']['buffer_pool_pressure']);
    }

    // ---------------------------------------------------------------
    // 17. Custom thresholds respected
    // ---------------------------------------------------------------

    public function test_custom_thresholds_respected(): void
    {
        // Use very low thresholds: high=2MB, moderate=1MB
        $customAnalyzer = new MemoryPressureAnalyzer(
            highThresholdBytes: 2097152,   // 2MB
            moderateThresholdBytes: 1048576, // 1MB
        );

        // 3000 rows disk spill: disk_spill = 768000. Page estimation: pages = ceil(768000/16384) = 47.
        // BP reads = 770048. Total = 768000 + 770048 = 1,538,048 (~1.5MB) → between 1MB and 2MB → moderate.
        $metrics = [
            'rows_examined' => 3000,
            'has_filesort' => false,
            'has_temp_table' => false,
            'has_disk_temp' => true,
            'join_count' => 0,
        ];

        $resultCustom = $customAnalyzer->analyze($metrics, null, null);
        $this->assertSame('moderate', $resultCustom['memory_pressure']['memory_risk']);

        // Same metrics with default analyzer → low (1.5MB << 64MB)
        $resultDefault = $this->analyzer->analyze($metrics, null, null);
        $this->assertSame('low', $resultDefault['memory_pressure']['memory_risk']);

        // 5000 rows disk spill: disk_spill = 1280000. Pages = 79. BP reads = 1294336.
        // Total = 1280000 + 1294336 = 2,574,336 > 2MB → high with custom
        $metricsHigh = [
            'rows_examined' => 5000,
            'has_filesort' => false,
            'has_temp_table' => false,
            'has_disk_temp' => true,
            'join_count' => 0,
        ];

        $resultCustomHigh = $customAnalyzer->analyze($metricsHigh, null, null);
        $this->assertSame('high', $resultCustomHigh['memory_pressure']['memory_risk']);

        // Default analyzer: 2.5MB << 64MB → still low
        $resultDefaultHigh = $this->analyzer->analyze($metricsHigh, null, null);
        $this->assertSame('low', $resultDefaultHigh['memory_pressure']['memory_risk']);
    }

    // ---------------------------------------------------------------
    // 18. Format bytes output
    // ---------------------------------------------------------------

    public function test_format_bytes_output(): void
    {
        // We test formatBytes indirectly through the finding descriptions.
        // Use a high-risk scenario to get a finding with formatted byte strings.

        // 1.1M rows * 256 = 281,600,000 bytes ~ 268.6 MB
        $metrics = [
            'rows_examined' => 1100000,
            'has_filesort' => false,
            'has_temp_table' => false,
            'has_disk_temp' => true,
            'join_count' => 0,
        ];

        $result = $this->analyzer->analyze($metrics, null, null);

        // Find the high memory pressure finding
        $highFindings = array_filter($result['findings'], fn ($f) => str_contains($f->title, 'High memory pressure'));
        $this->assertNotEmpty($highFindings);
        $finding = array_values($highFindings)[0];

        // Title should contain MB formatting
        $this->assertStringContainsString('MB', $finding->title);

        // Now test KB range: use small disk spill to get a disk spill finding
        $metricsSmall = [
            'rows_examined' => 10,
            'has_filesort' => false,
            'has_temp_table' => false,
            'has_disk_temp' => true,
            'join_count' => 0,
        ];

        $resultSmall = $this->analyzer->analyze($metricsSmall, null, null);
        $diskFindings = array_filter($resultSmall['findings'], fn ($f) => $f->title === 'Disk-based temporary table spill');
        $this->assertNotEmpty($diskFindings);
        $diskFinding = array_values($diskFindings)[0];
        // 10 * 256 = 2560 bytes = 2.5 KB
        $this->assertStringContainsString('KB', $diskFinding->description);

        // Test B range: 0 bytes should be "0 B"
        $metricsZero = [
            'rows_examined' => 0,
            'has_filesort' => false,
            'has_temp_table' => false,
            'has_disk_temp' => true, // disk_spill = 0 * 256 = 0
            'join_count' => 0,
        ];

        $resultZero = $this->analyzer->analyze($metricsZero, null, null);
        $zeroFindings = array_filter($resultZero['findings'], fn ($f) => $f->title === 'Disk-based temporary table spill');
        $this->assertNotEmpty($zeroFindings);
        $zeroFinding = array_values($zeroFindings)[0];
        $this->assertStringContainsString('0 B', $zeroFinding->description);
    }

    // ---------------------------------------------------------------
    // 19. All components combined
    // ---------------------------------------------------------------

    public function test_all_components_combined(): void
    {
        $metrics = [
            'rows_examined' => 50000,
            'has_filesort' => true,
            'has_temp_table' => true,
            'has_disk_temp' => true,
            'join_count' => 3,
        ];

        $environment = $this->makeEnvironment(
            bufferPoolSizeBytes: 1073741824, // 1GB
            innodbPageSize: 16384,
            tmpTableSize: 16777216,
        );

        $profile = $this->makeProfile(physicalReads: 500);

        $result = $this->analyzer->analyze($metrics, $environment, $profile);

        $components = $result['memory_pressure']['components'];

        // Sort buffer: min(262144, 50000*256) = 262144
        $this->assertSame(262144, $components['sort_buffer_bytes']);

        // Join buffer: (3-1) * 262144 = 524288
        $this->assertSame(524288, $components['join_buffer_bytes']);

        // Temp table: min(16777216, 50000*256) = min(16777216, 12800000) = 12800000
        $this->assertSame(12800000, $components['temp_table_bytes']);

        // Disk spill: 50000 * 256 = 12800000
        $this->assertSame(12800000, $components['disk_spill_bytes']);

        // Buffer pool reads: 500 physical reads * 16384 = 8192000
        $this->assertSame(8192000, $components['buffer_pool_reads_bytes']);

        // Total: 262144 + 524288 + 12800000 + 12800000 + 8192000 = 34578432
        $expectedTotal = 262144 + 524288 + 12800000 + 12800000 + 8192000;
        $this->assertSame($expectedTotal, $result['memory_pressure']['total_estimated_bytes']);

        // All components should be > 0
        foreach ($components as $key => $value) {
            $this->assertGreaterThan(0, $value, "Component '$key' should be > 0");
        }
    }

    // ---------------------------------------------------------------
    // 20. Zero rows examined - no memory (except join buffers)
    // ---------------------------------------------------------------

    public function test_zero_rows_examined_no_memory(): void
    {
        $metrics = [
            'rows_examined' => 0,
            'has_filesort' => true,
            'has_temp_table' => true,
            'has_disk_temp' => true,
            'join_count' => 0,
        ];

        $result = $this->analyzer->analyze($metrics, null, null);

        $components = $result['memory_pressure']['components'];

        // Sort buffer: min(262144, 0 * 256) = 0
        $this->assertSame(0, $components['sort_buffer_bytes']);

        // Join buffer: join_count=0, no buffers
        $this->assertSame(0, $components['join_buffer_bytes']);

        // Temp table: min(16777216, 0 * 256) = 0
        $this->assertSame(0, $components['temp_table_bytes']);

        // Disk spill: 0 * 256 = 0
        $this->assertSame(0, $components['disk_spill_bytes']);

        // Buffer pool reads: null profile = 0
        $this->assertSame(0, $components['buffer_pool_reads_bytes']);

        $this->assertSame(0, $result['memory_pressure']['total_estimated_bytes']);
    }

    // ---------------------------------------------------------------
    // Additional: Join count of 1 means no join buffers
    // ---------------------------------------------------------------

    public function test_single_join_no_buffer(): void
    {
        $metrics = [
            'rows_examined' => 100,
            'has_filesort' => false,
            'has_temp_table' => false,
            'has_disk_temp' => false,
            'join_count' => 1,
        ];

        $result = $this->analyzer->analyze($metrics, null, null);

        // join_count=1 means only one table, no join buffers needed
        $this->assertSame(0, $result['memory_pressure']['components']['join_buffer_bytes']);
    }

    // ---------------------------------------------------------------
    // Additional: Sort buffer recommendation when near capacity
    // ---------------------------------------------------------------

    public function test_sort_buffer_near_capacity_recommendation(): void
    {
        // Sort buffer is fully used (capped at sort_buffer_size)
        // 262144 / 256 = 1024 rows needed to fill sort buffer
        // Use more rows so the cap kicks in, meaning sortBufferBytes = sortBufferSize = 262144
        // sortBufferSize * 0.9 = 235929.6, and 262144 > 235929.6 → recommendation
        $metrics = [
            'rows_examined' => 2000,
            'has_filesort' => true,
            'has_temp_table' => false,
            'has_disk_temp' => false,
            'join_count' => 0,
        ];

        // But we need total > moderate threshold to get a finding that includes recommendations,
        // OR just check the recommendations array directly
        $result = $this->analyzer->analyze($metrics, null, null);

        $recommendations = $result['memory_pressure']['recommendations'];
        $found = false;
        foreach ($recommendations as $rec) {
            if (str_contains($rec, 'sort_buffer_size') || str_contains($rec, 'Sort buffer')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Expected recommendation about sort buffer being undersized');
    }

    // ---------------------------------------------------------------
    // Additional: Disk spill finding has correct description
    // ---------------------------------------------------------------

    public function test_disk_spill_finding_description(): void
    {
        $metrics = [
            'rows_examined' => 5000,
            'has_filesort' => false,
            'has_temp_table' => false,
            'has_disk_temp' => true,
            'join_count' => 0,
        ];

        $environment = $this->makeEnvironment(tmpTableSize: 8388608); // 8MB

        $result = $this->analyzer->analyze($metrics, $environment, null);

        $diskFindings = array_filter($result['findings'], fn ($f) => $f->title === 'Disk-based temporary table spill');
        $this->assertNotEmpty($diskFindings);
        $diskFinding = array_values($diskFindings)[0];

        $this->assertStringContainsString('tmp_table_size', $diskFinding->description);
        $this->assertStringContainsString('8.0 MB', $diskFinding->description);
        $this->assertNotNull($diskFinding->recommendation);
        $this->assertStringContainsString('tmp_table_size', $diskFinding->recommendation);
    }

    // ---------------------------------------------------------------
    // Additional: Zero buffer pool size avoids division by zero
    // ---------------------------------------------------------------

    public function test_zero_buffer_pool_size_no_division_error(): void
    {
        $metrics = [
            'rows_examined' => 100,
            'has_filesort' => false,
            'has_temp_table' => false,
            'has_disk_temp' => false,
            'join_count' => 0,
        ];

        $environment = $this->makeEnvironment(bufferPoolSizeBytes: 0);
        $profile = $this->makeProfile(logicalReads: 100);

        $result = $this->analyzer->analyze($metrics, $environment, $profile);

        $this->assertEqualsWithDelta(0.0, $result['memory_pressure']['buffer_pool_pressure'], 0.0001);
    }
}
