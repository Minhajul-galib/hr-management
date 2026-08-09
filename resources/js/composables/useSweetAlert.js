import Swal from 'sweetalert2';

export function useSweetAlert() {
    const showSuccess = (message, title = 'Success!') => {
        return Swal.fire({
            icon: 'success',
            title: title,
            text: message,
            confirmButtonColor: '#3085d6',
            timer: 3000,
            timerProgressBar: true,
        });
    };

    const showError = (message, title = 'Error!') => {
        return Swal.fire({
            icon: 'error',
            title: title,
            text: message,
            confirmButtonColor: '#d33',
        });
    };

    const showWarning = (message, title = 'Warning!') => {
        return Swal.fire({
            icon: 'warning',
            title: title,
            text: message,
            confirmButtonColor: '#f0ad4e',
        });
    };

    const showInfo = (message, title = 'Info') => {
        return Swal.fire({
            icon: 'info',
            title: title,
            text: message,
            confirmButtonColor: '#5bc0de',
        });
    };

    const showConfirm = (message, title = 'Are you sure?') => {
        return Swal.fire({
            title: title,
            text: message,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Yes, proceed!',
            cancelButtonText: 'Cancel',
        });
    };

    const showDeleteConfirm = (message = 'Do you want to delete this item?') => {
        return Swal.fire({
            title: 'Are you sure?',
            html: message,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, delete!',
            cancelButtonText: 'Cancel',
        });
    };

    const showTrashDeleteConfirm = (itemName = 'this item') => {
        return Swal.fire({
            title: 'Are you sure?',
            text: `Do you want to move ${itemName} to Trash or delete permanently?`,
            icon: 'warning',
            showCancelButton: true,
            showDenyButton: true,
            confirmButtonColor: '#f59e0b',
            denyButtonColor: '#d33',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Move to Trash',
            denyButtonText: 'Delete Permanently',
            cancelButtonText: 'Cancel',
        });
    };

    const showLoading = (message = 'Processing...') => {
        return Swal.fire({
            title: message,
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            },
        });
    };

    const closeLoading = () => {
        Swal.close();
    };

    return {
        showSuccess,
        showError,
        showWarning,
        showInfo,
        showConfirm,
        showDeleteConfirm,
        showTrashDeleteConfirm,
        showLoading,
        closeLoading,
    };
}
