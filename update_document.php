<?php
$content = file_get_contents('/root/development/supplier-management/app/Models/Document.php');

// 1. Update canPerformAction
$oldStr1 = '        if ($hasTransition) return true;';
$newStr1 = '        if ((hasTransition) return true;

        // 2. Check for workflow-specific permissions
        if ($user->hasPermissionTo('workflow-' . $currentStatus->slug)) {
            if ($actionType === 'approve' || $actionType === 'submit') {
                return DocumentStatus::where('serial', '>', $currentStatus->serial)->exists();
            }
            if ($actionType === 'recheck') {
                return DocumentStatus::where('serial', '<', $currentStatus->serial)->exists();
            }
        }';

$content = str_replace($oldStr1, $newStr1, $content);

// 2. Update performWorkflowAction fallback
$oldStr2 = '$isHandler = $currentStatus->role_id && in_array($currentStatus->role_id, $userRoleIds);';
$newStr2 = $isHandler = $currentStatus->role_id && in_array($currentStatus->role_id, $userRoleIds);
            $allowedByPermission = $user->hasPermissionTo('workflow-' . $currentStatus->slug);

            if (!$isHandler && !$allowedByPermission && !$user->hasRole('Super System Admin')) {
                return false;
            }';

$content = str_replace($oldStr2, $newStr2, $content);

file_put_contents('/root/development/supplier-management/app/Models/Document.php', $content);