# Project Management — Controls

## ADDED Requirements

### Requirement: Budget and resources
The system SHALL record approved budget, cost entries, currency and allocations with explicit authorization.

#### Scenario: Read-only viewer without financial grant
- **WHEN** a viewer sees a project but lacks `projects.finance.view`
- **THEN** financial amounts are absent from detail, API, search, exports and aggregated dashboards.

### Requirement: Progress and health
The system SHALL distinguish reported progress, milestone completion and measured deliverable progress; each metric SHALL state its calculation basis.

#### Scenario: Project has open tasks but accepted milestone
- **WHEN** an accepted milestone is completed but other tasks remain open
- **THEN** the system does not automatically set overall progress to 100%.

### Requirement: Visibility grants
The system SHALL apply existing monitoring grants to general and hybrid projects while respecting confidential projects and finance restrictions.

#### Scenario: Viewer granted team scope
- **WHEN** a viewer has a grant for one team, optionally including descendants
- **THEN** only authorized project and work records appear in dashboards, search and reports, and edits remain forbidden.

### Requirement: Formal scope change
The system SHALL link material scope changes to a versioned Request or authorized decision, preserving the initial approved scope.

#### Scenario: Signed requirement changes
- **WHEN** approved project scope derived from signed REQ changes
- **THEN** a new revision and approval trail is created; the original signed document is unchanged.
