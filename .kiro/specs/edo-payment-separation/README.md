# eDO Payment Separation Feature Specification

## Overview

This feature specification addresses the confusion caused by storing both shipping line billing payments and eDO release payments in a single `payments` table. The solution creates a separate `payments_edo` table specifically for eDO release payments handled by SYSTEM_ADMIN, while keeping the existing `payments` table exclusively for shipping line transactions handled by ACCOUNTING.

## Problem Statement

Currently, the OPTIMUS shipping management platform stores all payment types in a single `payments` table:
- **Final Payments** (shipping line billing) - validated by ACCOUNTING role
- **Manifest Access Payments** (eDO release) - validated by SYSTEM_ADMIN role

This causes several issues:
1. Both roles see each other's transactions in the same table
2. Payment validation dashboards show mixed payment types
3. Unclear separation of responsibilities between roles
4. Difficult to generate role-specific reports
5. Confusing user experience for both ACCOUNTING and SYSTEM_ADMIN

## Solution

Create a clear separation by:
1. Creating a new `EDOPayment` entity and `payments_edo` table
2. Migrating existing `manifest_access` payment records to the new table
3. Splitting `PaymentService` into `PaymentService` (billing) and `EDOPaymentService` (eDO)
4. Creating separate validation dashboards for each role
5. Updating all relationships and references

## Key Benefits

- **Clear Role Separation**: ACCOUNTING only sees billing payments, SYSTEM_ADMIN only sees eDO payments
- **Improved User Experience**: Each role has a focused dashboard for their payment type
- **Better Data Organization**: Payment types are physically separated in the database
- **Easier Reporting**: Role-specific reports can query dedicated tables
- **Maintainability**: Clear service boundaries and responsibilities

## Documents

- **[REQUIREMENTS.md](./REQUIREMENTS.md)**: Detailed requirements with 20 user stories and acceptance criteria
- **[DESIGN.md](./DESIGN.md)**: Technical design including database schema, entity design, service layer, controllers, templates, and migration strategy
- **[TASKS.md](./TASKS.md)**: Implementation tasks organized in 10 phases with 150+ subtasks

## Architecture

### Database Layer
- **payments** table: Stores final payments (billing) only
- **payments_edo** table: Stores eDO access payments only
- **Relationships**: Manifest has both `payments` and `edoPayments` collections

### Entity Layer
- **Payment**: Represents shipping line billing payments
- **EDOPayment**: Represents eDO release payments (new)
- **Manifest**: Updated with `edoPayments` relationship
- **ElectronicDeliveryOrder**: Updated to reference `EDOPayment`

### Service Layer
- **PaymentService**: Handles final payment submission and validation (ACCOUNTING)
- **EDOPaymentService**: Handles eDO payment submission and validation (SYSTEM_ADMIN) (new)
- **EDOReleaseService**: Updated to work with EDOPayment
- **ManifestNotificationService**: Updated with eDO payment notifications

### Controller Layer
- **AccountingPaymentController**: Displays only final payments
- **EDOPaymentController**: Displays only eDO payments (new)
- **BrokerManifestController**: Updated to use EDOPaymentService

### Template Layer
- **accounting/payment/index.html.twig**: Final payment validation dashboard
- **admin/edo_payment/index.html.twig**: eDO payment validation dashboard (new)
- **broker/manifest/edo_payment.html.twig**: Updated eDO payment submission form

## Implementation Phases

1. **Phase 1**: Database Schema and Migration
2. **Phase 2**: Entity Layer
3. **Phase 3**: Service Layer
4. **Phase 4**: Controller Layer
5. **Phase 5**: Template Layer
6. **Phase 6**: Configuration and Services
7. **Phase 7**: Testing
8. **Phase 8**: Documentation
9. **Phase 9**: Deployment
10. **Phase 10**: Cleanup and Optimization

## Migration Strategy

### Data Migration Steps
1. Create `payments_edo` table
2. Copy `manifest_access` payment records from `payments` to `payments_edo`
3. Update `electronic_delivery_orders` foreign keys to reference `payments_edo`
4. Delete `manifest_access` records from `payments` table
5. Verify data integrity

### Rollback Plan
If issues are encountered:
1. Restore previous code version
2. Copy records from `payments_edo` back to `payments`
3. Update foreign key constraints
4. Drop `payments_edo` table
5. Verify system functionality

## Testing Strategy

- **Unit Tests**: Entity methods, service methods, repository methods
- **Integration Tests**: Database operations, foreign key constraints, data migration
- **Functional Tests**: Payment workflows, role-based access control, notifications
- **End-to-End Tests**: Complete workflows from submission to validation
- **Performance Tests**: Dashboard load times, query performance

## Correctness Properties

1. **Payment Type Separation**: All Payment records are FINAL_PAYMENT, all EDOPayment records are for manifest access
2. **Unique eDO Payment Per Manifest**: Each manifest has at most one EDOPayment
3. **EDO References EDOPayment**: All ElectronicDeliveryOrder records reference EDOPayment
4. **Role-Based Access Enforcement**: ACCOUNTING accesses Payment, SYSTEM_ADMIN accesses EDOPayment
5. **Data Migration Completeness**: All manifest_access payments migrated successfully
6. **Audit Trail Completeness**: All payment operations logged with user identity
7. **Notification Delivery**: Notifications sent to correct roles for each event
8. **Payment Status Transitions**: Valid state machine transitions enforced
9. **EDO Release on Payment Approval**: EDO automatically released when payment approved
10. **Foreign Key Integrity**: All EDOPayment records reference valid entities

## Dependencies

- Symfony 6.x
- Doctrine ORM
- PHP 8.1+
- MySQL 8.0+
- Existing entities: Payment, Manifest, ElectronicDeliveryOrder, User
- Existing services: FileService, AuditService, NotificationService, ActivityLogService

## Affected Components

### New Components
- `src/Entity/EDOPayment.php`
- `src/Repository/EDOPaymentRepository.php`
- `src/Service/EDOPaymentService.php`
- `src/Service/EDOPaymentServiceInterface.php`
- `src/Controller/Admin/EDOPaymentController.php`
- `templates/admin/edo_payment/index.html.twig`

### Modified Components
- `src/Entity/Manifest.php`
- `src/Entity/ElectronicDeliveryOrder.php`
- `src/Service/PaymentService.php`
- `src/Service/EDOReleaseService.php`
- `src/Service/ManifestNotificationService.php`
- `src/Service/ActivityLogService.php`
- `src/Controller/Broker/BrokerManifestController.php`
- `src/Controller/Accounting/AccountingPaymentController.php`
- `templates/accounting/payment/index.html.twig`
- `templates/broker/manifest/edo_payment.html.twig`
- `templates/admin/edo/queue.html.twig`

## Timeline Estimate

- **Phase 1-2 (Database & Entities)**: 3-4 days
- **Phase 3 (Service Layer)**: 4-5 days
- **Phase 4-5 (Controllers & Templates)**: 3-4 days
- **Phase 6 (Configuration)**: 1 day
- **Phase 7 (Testing)**: 5-6 days
- **Phase 8 (Documentation)**: 2-3 days
- **Phase 9 (Deployment)**: 2-3 days
- **Phase 10 (Cleanup)**: 1-2 days

**Total Estimated Time**: 22-32 days

## Success Criteria

- All 20 requirements implemented and tested
- All 150+ tasks completed
- All 10 correctness properties verified
- 100% test pass rate (unit, integration, functional, end-to-end)
- Successful data migration with zero data loss
- Role-based access control enforced correctly
- Performance requirements met (dashboard load < 2 seconds)
- User acceptance testing passed
- Production deployment successful with zero downtime
- Post-deployment monitoring shows no critical issues

## Risks and Mitigations

### Risk 1: Data Migration Failure
**Mitigation**: 
- Test migration script thoroughly on staging
- Backup production database before migration
- Implement rollback procedures
- Verify data integrity after migration

### Risk 2: Performance Degradation
**Mitigation**:
- Add database indexes on frequently queried columns
- Use eager loading for related entities
- Implement pagination for large result sets
- Monitor query performance with EXPLAIN

### Risk 3: User Confusion
**Mitigation**:
- Provide clear user documentation
- Conduct user training sessions
- Add helpful UI messages and tooltips
- Collect user feedback and iterate

### Risk 4: Breaking Existing Functionality
**Mitigation**:
- Comprehensive test coverage
- Thorough code review
- Staging environment testing
- Gradual rollout with monitoring

## Support and Maintenance

- **Bug Reports**: Submit via issue tracker with detailed reproduction steps
- **Feature Requests**: Submit via feature request form with business justification
- **Documentation**: Available in `/docs` directory and inline code comments
- **Monitoring**: Application logs, database performance metrics, user feedback

## Version History

- **v1.0.0** (2024-04-12): Initial specification created
  - 20 requirements defined
  - Technical design completed
  - 150+ implementation tasks identified
  - 10 correctness properties defined

## Contributors

- Feature Specification: Kiro AI Assistant
- Technical Review: [To be assigned]
- Implementation: [To be assigned]
- Testing: [To be assigned]
- Documentation: [To be assigned]

## References

- [OPTIMUS Shipping Portal Documentation](../optimus-shipping-portal/)
- [eDO Release Workflow Specification](../edo-release-workflow/)
- [Manifest Payment NOA Workflow Specification](../manifest-payment-noa-workflow/)
- [Symfony Documentation](https://symfony.com/doc/current/index.html)
- [Doctrine ORM Documentation](https://www.doctrine-project.org/projects/doctrine-orm/en/latest/)
