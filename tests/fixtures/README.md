# Dwell Time Test Data Fixtures

This directory contains comprehensive test data generation tools for the Container Dwell Time Management feature.

## Overview

The test data factory provides a complete solution for generating realistic container test data with various dwell time scenarios, pause/resume patterns, and edge cases. This enables thorough testing of all 15 correctness properties defined in the design document.

## Files in This Directory

### 1. `DwellTimeTestDataFactory.php`
The main factory class containing 15+ methods for generating test containers and audit trails.

**Key Features:**
- Generates containers with realistic data (unique numbers, sizes, types)
- Supports all dwell time stages (0-30, 30-60, 60-90, 90+ days)
- Creates containers with single or multiple pause/resume cycles
- Handles edge cases (leap years, timezone boundaries)
- Generates complete audit trails with event history

### 2. `DWELL_TIME_TEST_SCENARIOS.md`
Comprehensive documentation of all test scenarios and factory methods.

**Contents:**
- Detailed description of each factory method
- Parameters and their meanings
- Use cases for each scenario
- Code examples for each method
- Property validation matrix
- Best practices and maintenance guidelines

### 3. `USAGE_EXAMPLES.md`
Practical examples showing how to use the factory in real tests.

**Includes:**
- Unit testing examples
- Integration testing examples
- Functional testing examples
- Property-based testing examples
- Performance testing examples
- Tips and best practices

### 4. `QUICK_REFERENCE.md`
Quick lookup guide for common testing patterns.

**Contains:**
- Method selection table
- Common test patterns
- Property validation reference
- Key container properties
- Import statements

### 5. `README.md` (this file)
Overview and getting started guide.

## Quick Start

### Installation

The factory is already available in your test suite. Simply import it:

```php
use App\Tests\Fixtures\DwellTimeTestDataFactory;
```

### Basic Usage

```php
// Create a container 5 days before 60-day notification
$container = DwellTimeTestDataFactory::createContainerApproaching60Days(-5);

// Create a container with a 10-day pause
$container = DwellTimeTestDataFactory::createContainerWithSinglePause(10, 50);

// Create 20 containers with mixed scenarios
$containers = DwellTimeTestDataFactory::createMixedScenarioBatch(20);

// Create complete audit trail
$events = DwellTimeTestDataFactory::createCompleteAuditTrail($container, $user);
```

## Available Factory Methods

### Threshold Scenarios
- `createContainerApproaching60Days($daysFromThreshold)` - Containers near notification threshold
- `createContainerApproaching90Days($daysFromThreshold)` - Containers near return threshold

### Stage-Based Scenarios
- `createContainerInStage($stage)` - Containers in specific dwell time stages
  - Stages: 'early' (0-30), 'mid' (30-60), 'warning' (60-90), 'overdue' (90+)

### Pause/Resume Scenarios
- `createContainerWithSinglePause($pauseDays, $totalDays)` - Single pause cycle
- `createContainerWithMultiplePauses($pauseArray, $totalDays)` - Multiple pause cycles
- `createContainerInAlertStatus($daysBeforePause, $daysSincePause)` - Currently paused
- `createResumedContainer($pauseDuration, $daysSinceResume)` - Recently resumed

### Edge Cases
- `createLeapYearContainer($daysAgo)` - Leap year date calculations
- `createContainerWithNotificationSent($daysSince)` - Notification already sent
- `createReturnedContainer($daysOver)` - Already returned container

### Audit Trail
- `createCompleteAuditTrail($container, $user)` - Generate event history

### Batch Generation
- `createMixedScenarioBatch($count)` - Multiple containers with varied scenarios

## Testing Coverage

The factory supports testing all 15 correctness properties:

| Property | Description | Factory Methods |
|----------|-------------|-----------------|
| P1 | 60-Day Notification Trigger | `createContainerApproaching60Days()` |
| P2 | 90-Day Automatic Return | `createContainerApproaching90Days()` |
| P3 | Notification Content | `createContainerApproaching60Days()` |
| P4 | Alert Status Pauses Counting | `createContainerInAlertStatus()` |
| P5 | Status Change Triggers | `createContainerInAlertStatus()`, `createResumedContainer()` |
| P6 | Calculation Accuracy | All methods, especially `createLeapYearContainer()` |
| P7 | Date Recalculation | `createResumedContainer()` |
| P8 | Audit Trail | `createCompleteAuditTrail()` |
| P9 | Terminology | N/A (UI/API testing) |
| P10 | Multi-Channel Delivery | `createContainerApproaching60Days()` |
| P11 | Return Status Update | `createReturnedContainer()` |
| P12 | Terminal Integration | All methods |
| P13 | Alert Visibility | `createContainerInAlertStatus()` |
| P14 | Dashboard Accuracy | `createMixedScenarioBatch()` |
| P15 | Pause Duration | `createContainerWithSinglePause()`, `createContainerWithMultiplePauses()` |

## Example Test

```php
<?php

namespace App\Tests\Unit\Service;

use App\Service\DwellTimeService;
use App\Tests\Fixtures\DwellTimeTestDataFactory;
use PHPUnit\Framework\TestCase;

class DwellTimeServiceTest extends TestCase
{
    public function testNotificationTriggeredAt60Days(): void
    {
        // Arrange: Create container at 60-day threshold
        $container = DwellTimeTestDataFactory::createContainerApproaching60Days(0);
        $service = new DwellTimeService(/* dependencies */);
        
        // Act: Check notification thresholds
        $notifications = $service->checkNotificationThresholds($container);
        
        // Assert: Notification should be triggered
        $this->assertCount(1, $notifications);
        $this->assertEquals('notification_60_day', $notifications[0]['type']);
        $this->assertEquals(60, $notifications[0]['dwell_time']);
        $this->assertEquals(30, $notifications[0]['days_remaining']);
    }
}
```

## Documentation Structure

```
tests/fixtures/
├── DwellTimeTestDataFactory.php      # Main factory class
├── README.md                          # This file (overview)
├── DWELL_TIME_TEST_SCENARIOS.md      # Detailed scenario documentation
├── USAGE_EXAMPLES.md                 # Practical usage examples
└── QUICK_REFERENCE.md                # Quick lookup guide
```

## Validation

The factory includes its own test suite to ensure data generation is correct:

```bash
php bin/phpunit tests/Unit/Fixtures/DwellTimeTestDataFactoryTest.php
```

All 17 validation tests pass, ensuring:
- Containers have correct dwell times
- Pause durations are calculated accurately
- Dates are calculated correctly
- Audit trails are complete
- Container numbers are unique
- Batch generation works properly

## Best Practices

### 1. Use Specific Methods
Choose the most specific factory method for your test case:

```php
// ✅ Good: Specific method
$container = DwellTimeTestDataFactory::createContainerApproaching60Days(-5);

// ❌ Bad: Manual creation
$container = new Container();
$container->setTerminalArrivalDate(new \DateTime('-55 days'));
// ... many more lines
```

### 2. Combine with Audit Trails
Add realistic event history to containers:

```php
$container = DwellTimeTestDataFactory::createContainerWithSinglePause(10, 65);
$events = DwellTimeTestDataFactory::createCompleteAuditTrail($container, $user);
foreach ($events as $event) {
    $entityManager->persist($event);
}
```

### 3. Use Batch Generation
For integration and performance tests:

```php
$containers = DwellTimeTestDataFactory::createMixedScenarioBatch(100);
foreach ($containers as $container) {
    $entityManager->persist($container);
}
$entityManager->flush();
```

### 4. Test Edge Cases
Always include edge cases in your test suite:

```php
$edgeCases = [
    DwellTimeTestDataFactory::createLeapYearContainer(65),
    DwellTimeTestDataFactory::createContainerApproaching60Days(0),
    DwellTimeTestDataFactory::createContainerApproaching90Days(0),
];
```

## Requirements Coverage

The factory generates test data that validates all requirements:

- **Requirement 1**: 60-Day Notification (P1, P3)
- **Requirement 2**: 90-Day Automatic Return (P2, P11)
- **Requirement 3**: Alert Status Pause (P4, P13)
- **Requirement 4**: Alert Status Resume (P5, P7, P15)
- **Requirement 5**: FREE-ADVICE Terminology (P9)
- **Requirement 6**: Terminal Team Integration (P12, P13)
- **Requirement 7**: Calculation Accuracy (P6, P8)
- **Requirement 8**: Notification Reliability (P10, P14)

## Support

For detailed information:
- **Scenarios**: See `DWELL_TIME_TEST_SCENARIOS.md`
- **Examples**: See `USAGE_EXAMPLES.md`
- **Quick Reference**: See `QUICK_REFERENCE.md`

For questions or issues with the factory, refer to the comprehensive documentation files or examine the validation tests in `tests/Unit/Fixtures/DwellTimeTestDataFactoryTest.php`.

## Maintenance

When adding new scenarios:
1. Add method to `DwellTimeTestDataFactory.php`
2. Document in `DWELL_TIME_TEST_SCENARIOS.md`
3. Add example to `USAGE_EXAMPLES.md`
4. Update `QUICK_REFERENCE.md`
5. Add validation test to `DwellTimeTestDataFactoryTest.php`
6. Update this README if needed

## Version

Factory Version: 1.0.0
Created: 2026-03-30
Last Updated: 2026-03-30
