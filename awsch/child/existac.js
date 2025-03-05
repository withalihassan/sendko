$(document).ready(function () {
    // Use the parentAccountId from the PHP variable passed to JS
    var parentAccountId = window.parentAccountId;

    // Fetch existing child accounts when the button is clicked
    $('#fetchExistingAccounts').click(function () {
        $.ajax({
            url: 'child/fetch_existing_child_ac.php',  // Endpoint for fetching child accounts
            type: 'GET',
            data: { parent_id: parentAccountId },  // Send the parent account ID
            success: function (response) {
                // Update the table with the fetched child accounts
                $('#childAccountsTable').html(response);
            },
            error: function () {
                // Handle error when fetching accounts
                alert('Failed to fetch child accounts.');
            }
        });
    });

});
