<?php
$path = '/root/development/supplier-management/app/Models/Document.php';
$content = file_get_contents($path);

// 1. Update canPerformAction
$old1 = '        if ($hasTransition) return true;';
$new1 = '        if ((hasTransition) return true;

        // 2. Check for workflow-specific permissions
        if ($user->hasPermissionTo("workflow-" . $currentStatus->slug)) {
            if ($actionType === "approve" || $actionType === "submit") {
                return DocumentStatus::where("serial", ">", $currentStatus->serial)->exists();
            }
            if ($actionType === "recheck") {
                return DocumentStatus::where("serial", "<", $currentStatus->serial)->exists();
            }
        }';

if (strpos($content, 'Check for workflow-specific permissions') === false) {
    $content = str_replace($old1, $new1, $content);
}

// 2. Update performWorkflowAction fallback
$old2 = '            $isHandler = $currentStatus->role_id && in_array($currentStatus->role_id, $userRoleIds);';
$new2 = '            $isHandler = $currentStatus->role_id && in_array($currentStatus->role_id, $userRoleIds);
            $allowedByPermission = $user->hasPermissionTo("workflow-" . $currentStatus->slug);

            if (!$isHandler && !$allowedByPermission && !$user->hasRole("Super System Admin')) {
                return false;
            }';

if (strpos($content, '$allowedByPermission') === false) {
    $contenu = str_replace($old2, $new2, $content);
}

file_put_contents($path, $content);
echo "Update completed successfully.\n";
