# Project Management — Lifecycle

## ADDED Requirements

### Requirement: Project types
The system SHALL support general, software and hybrid projects under one project identity, without forcing engineering tools on general projects.

#### Scenario: Operational project without repository
- **WHEN** a manager creates a general project with no GitHub repository or OpenSpec change
- **THEN** the project can be planned, assigned, monitored and closed without any engineering integration.

### Requirement: Charter and planning
The system SHALL support objectives, scope, deliverables, milestones, ownership, schedule and approved baselines.

#### Scenario: Baseline revision
- **WHEN** a baseline is approved and the schedule changes materially
- **THEN** the old baseline remains immutable and a new revision requires authorized approval.

### Requirement: Cross-project dependencies
The system SHALL detect cycles and enforce organization scope when linking project dependencies.

#### Scenario: Cyclic dependency
- **WHEN** a user attempts to link project A after B while B already depends transitively on A
- **THEN** the operation is rejected with an explanatory validation message.

### Requirement: Risk and decision governance
The system SHALL record risks, issues and decisions with ownership and auditable transitions.

#### Scenario: Escalating risk
- **WHEN** a risk becomes critical
- **THEN** authorized stakeholders are notified and the transition is written to the audit log.

### Requirement: Completion and acceptance
The system SHALL allow closure only after required deliverables are accepted or exceptions are explicitly approved.

#### Scenario: Closing with pending deliverables
- **WHEN** a manager requests closure while mandatory deliverables lack acceptance
- **THEN** closure is blocked unless an authorized exception is recorded with justification.
