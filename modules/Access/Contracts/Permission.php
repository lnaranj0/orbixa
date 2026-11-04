<?php

namespace Modules\Access\Contracts;

/**
 * Permission catalog (docs/PERMISSIONS.md). Kept in code, not in a global
 * table, so it is versioned and reviewed with the code that checks it.
 */
enum Permission: string
{
    case OrganizationManage = 'organization.manage';
    case MembersInvite = 'members.invite';
    case MembersManage = 'members.manage';
    case TeamsManage = 'teams.manage';
    case ProjectsCreate = 'projects.create';
    case ProjectsManage = 'projects.manage';
    case WorkCreate = 'work.create';
    case WorkEdit = 'work.edit';
    case WorkTransition = 'work.transition';
    case WorkAssign = 'work.assign';
    case WorkApprove = 'work.approve';
    case PlanningManage = 'planning.manage';
    case IntegrationsGithubManage = 'integrations.github.manage';
    case SpecsLink = 'specs.link';
    case AgentsManage = 'agents.manage';
    case AgentsAssign = 'agents.assign';
    case AgentsExecute = 'agents.execute';
    case AuditView = 'audit.view';
    // 04-monitoring-access (M4)
    case MonitoringView = 'monitoring.view';
    case MonitoringGrantsView = 'monitoring.grants.view';
    case MonitoringGrantsManage = 'monitoring.grants.manage';
    case MonitoringRestrictedView = 'monitoring.restricted.view';
    case MonitoringExport = 'monitoring.export';
}
