# TailAdmin-inspired UI

## ADDED Requirements

### Requirement: Consistent application shell
The system SHALL provide one shared responsive shell with a collapsible navy sidebar, topbar, breadcrumbs, organization context and a content area built from semantic tokens.

#### Scenario: Navigation across modules
- **WHEN** a user navigates between Project Management, Backlog and Requests
- **THEN** navigation shell, component spacing, active states and accessible behavior remain consistent.

### Requirement: Reusable data lists
The system SHALL expose reusable data table and filter components with server pagination, search, state badges and permitted actions.

#### Scenario: Large portfolio listing
- **WHEN** the organization has thousands of projects
- **THEN** the listing paginates on the server and preserves active filters without downloading all records.

### Requirement: Accessible responsive themes
The system SHALL support light/dark modes, visible keyboard focus, responsive layouts, and accessible contrast in both themes.

#### Scenario: Mobile and keyboard
- **WHEN** a user browses at 375px width using a keyboard or assistive technology
- **THEN** the sidebar is operable, controls are labeled, and page content remains usable without global horizontal overflow.

### Requirement: Presentation does not grant authorization
The system SHALL use server authorization for all operations even if controls are hidden or disabled in UI.

#### Scenario: Viewer crafts edit request
- **WHEN** a read-only viewer calls an update endpoint directly
- **THEN** the operation is rejected and the underlying data is unchanged.
