document.addEventListener('DOMContentLoaded', function() {
    var deleteButtons = document.querySelectorAll('.delete-feedback');

    for (var i = 0; i < deleteButtons.length; i++) {
        deleteButtons[i].addEventListener('click', function(event) {
            var transientName = event.target.getAttribute('data-transient');

            if (confirm('Are you sure you want to delete this feedback?')) {
                var xhr = new XMLHttpRequest();
                xhr.open('POST', employee_feedback_data.ajax_url, true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');

                xhr.onload = function() {
                    if (xhr.status === 200 && xhr.responseText === 'success') {
                        // Reload the page to update the feedback list
                        location.reload();
                    } else {
                        alert('Error deleting feedback.');
                    }
                };

                xhr.send('action=delete_employee_feedback&transient_name=' + encodeURIComponent(transientName));
            }
        });
    }
});
