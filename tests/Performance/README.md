# Terminal Team Pre-Advice Performance Testing

This directory contains comprehensive performance and load testing for the Terminal Team Pre-Advice system.

## Test Files

### 1. TerminalTeamPreAdvicePerformanceTestSimplified.php
**Purpose**: Simplified performance testing without database dependencies
**Tests**:
- Photo upload performance with large files (up to 8MB)
- Concurrent pre-advice submissions simulation (50 operations)
- Memory usage during bulk operations (1000+ records)
- File I/O performance for photo operations (100 files)
- CPU-intensive operations (GPS validation, EXIF processing, QR generation)

**Performance Thresholds**:
- Photo upload: < 10 seconds per 8MB file
- Concurrent operations: < 30 seconds for 50 operations
- Memory usage: < 256MB peak
- File I/O: < 1 second per file
- CPU operations: < 10 seconds per operation set

### 2. TerminalTeamPreAdvicePerformanceTest.php
**Purpose**: Full system performance testing with database integration
**Tests**:
- Container search with large datasets (1000+ containers)
- Terminal availability checking with large slot datasets
- Database query performance with complex joins
- Pre-advice workflow performance end-to-end
- Photo verification and processing performance

**Note**: Requires database schema to be created first

### 3. LoadTestScript.php
**Purpose**: Standalone load testing script for stress testing
**Configuration**:
- Concurrent users: 100
- Operations per user: 10
- Test duration: 5 minutes
- Ramp-up time: 1 minute

### 4. DatabasePerformanceBenchmark.php
**Purpose**: Database-specific performance benchmarking
**Tests**:
- Query performance with large datasets
- Bulk insert operations
- Connection pool performance
- Complex query optimization

## Running Performance Tests

### Quick Performance Check
```bash
# Run simplified tests (no database required)
php bin/phpunit tests/Performance/TerminalTeamPreAdvicePerformanceTestSimplified.php --testdox
```

### Full Performance Suite
```bash
# Run all performance tests (requires database setup)
php bin/phpunit tests/Performance/ --testdox
```

### Individual Test Categories
```bash
# Memory usage test
php bin/phpunit tests/Performance/TerminalTeamPreAdvicePerformanceTestSimplified.php --filter testMemoryUsageDuringBulkOperations

# Photo upload performance
php bin/phpunit tests/Performance/TerminalTeamPreAdvicePerformanceTestSimplified.php --filter testPhotoUploadPerformanceWithLargeFiles

# Concurrent operations
php bin/phpunit tests/Performance/TerminalTeamPreAdvicePerformanceTestSimplified.php --filter testConcurrentPreAdviceSubmissionsPerformanceSimulation
```

### Load Testing
```bash
# Run load test script
php bin/phpunit tests/Performance/LoadTestScript.php --filter testLoadTestExecution
```

## Performance Criteria

### System Requirements
The Terminal Team Pre-Advice system must meet these performance criteria:

1. **Response Time**:
   - Container search: < 2 seconds
   - Pre-advice submission: < 5 seconds
   - Photo upload (8MB): < 10 seconds
   - Dashboard load: < 3 seconds

2. **Throughput**:
   - Concurrent pre-advice submissions: ≥ 10 operations/second
   - Photo processing: ≥ 1 photo/second
   - Database queries: < 1 second average

3. **Resource Usage**:
   - Memory usage: < 256MB peak during bulk operations
   - CPU usage: < 80% during normal operations
   - Disk I/O: < 100MB/s for photo operations

4. **Scalability**:
   - Support 100+ concurrent users
   - Handle 10,000+ containers in database
   - Process 1,000+ pre-advice requests per day

### Performance Monitoring

The tests include built-in performance monitoring that tracks:
- Execution times for all operations
- Memory usage patterns
- Database query performance
- File I/O throughput
- Error rates and failure patterns

### Benchmarking Results

Example results from a typical test run:

```
=== Photo Upload Performance Test ===
Photo upload 0 (8MB): 0.052s ✓
Photo upload 1 (8MB): 0.074s ✓
Photo upload 2 (8MB): 0.076s ✓
Photo upload 3 (8MB): 0.046s ✓
Photo upload 4 (8MB): 0.092s ✓

=== Concurrent Operations Performance Test ===
Total concurrent operations: 50
Total execution time: 7.179s
Average time per operation: 0.144s ✓

=== Memory Usage Performance Test ===
Initial memory usage: 20.00 MB
Peak memory usage: 20.00 MB
Memory increase: 0.00 MB ✓

=== File I/O Performance Test ===
File I/O operations: 100
Total time: 0.315s
Average time per file: 0.003s ✓
```

## Troubleshooting Performance Issues

### Common Performance Problems

1. **Slow Photo Uploads**:
   - Check file system performance
   - Verify network bandwidth
   - Review photo processing algorithms

2. **Database Query Slowdowns**:
   - Add appropriate indexes
   - Optimize query structure
   - Consider query caching

3. **Memory Usage Issues**:
   - Review bulk operation batch sizes
   - Implement proper garbage collection
   - Optimize data structures

4. **Concurrent Operation Bottlenecks**:
   - Check database connection pool size
   - Review locking mechanisms
   - Optimize critical sections

### Performance Optimization Tips

1. **Database Optimization**:
   - Add indexes on frequently queried columns
   - Use appropriate data types
   - Implement query result caching
   - Batch database operations

2. **File Processing Optimization**:
   - Implement asynchronous file processing
   - Use appropriate file formats
   - Implement file compression
   - Cache processed results

3. **Memory Management**:
   - Use streaming for large datasets
   - Implement proper cleanup
   - Optimize object creation
   - Use appropriate data structures

4. **Caching Strategy**:
   - Cache frequently accessed data
   - Implement cache invalidation
   - Use appropriate cache levels
   - Monitor cache hit rates

## Integration with CI/CD

These performance tests can be integrated into CI/CD pipelines:

```yaml
# Example GitHub Actions workflow
- name: Run Performance Tests
  run: |
    php bin/phpunit tests/Performance/TerminalTeamPreAdvicePerformanceTestSimplified.php --testdox
    
- name: Performance Regression Check
  run: |
    # Compare results with baseline
    # Fail if performance degrades beyond threshold
```

## Monitoring and Alerting

Set up monitoring for:
- Response time percentiles (50th, 95th, 99th)
- Error rates and failure patterns
- Resource utilization trends
- Database performance metrics
- File processing throughput

Configure alerts for:
- Response times exceeding thresholds
- Error rates above acceptable levels
- Resource usage approaching limits
- Performance degradation trends