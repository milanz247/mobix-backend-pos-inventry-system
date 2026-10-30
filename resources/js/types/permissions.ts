export type PermissionAction = 'view' | 'add' | 'edit' | 'delete' | 'actions';
export type FunctionPermission = {
    id: number;
    name: string;
    label: string;
    feature: string;
    action: PermissionAction;
    description: string;
    requires: string[];
    assignable: boolean;
};
export type PermissionGroups = Record<string, FunctionPermission[]>;
export const permissionColumns: { key: PermissionAction; label: string }[] = [
    { key: 'view', label: 'View' },
    { key: 'add', label: 'Add' },
    { key: 'edit', label: 'Edit' },
    { key: 'delete', label: 'Delete' },
    { key: 'actions', label: 'Other actions' },
];
