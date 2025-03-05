$(document).ready(function () {
    // Use the parentAccountId from the PHP variable passed to JS
    var parentAccountId = window.parentAccountId;

    // Load existing child accounts
    function loadChildAccounts() {
        $.ajax({
            url: 'child/fetch_child_accounts.php',
            type: 'GET',
            data: { parent_id: parentAccountId },
            success: function (response) {
                $('#childAccountsTable').html(response);
            },
            error: function () {
                alert('Failed to fetch child accounts.');
            }
        });
    }

    loadChildAccounts(); // Initial load

    // Add Child Account Form Submission
    $('#addChildAccountForm').submit(function (e) {
        e.preventDefault();

        let email = $('#email').val();
        let name = $('#name').val();

        $.ajax({
            url: 'child/add_child_account.php',
            type: 'POST',
            data: { parent_id: parentAccountId, email: email, name: name },
            success: function (response) {
                alert(response);
                $('#addChildAccountForm')[0].reset();
                loadChildAccounts();
            },
            error: function () {
                alert('Failed to add child account.');
            }
        });
    });
});
