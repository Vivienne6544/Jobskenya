<?php
session_start();
require "db.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$user_id = (int) $_SESSION['user_id'];


/* ================= DELETE ACCOUNT ================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    if ($action === 'delete_account') {

        // Delete user's applications
        $stmt = $conn->prepare("
            DELETE FROM applications
            WHERE employee_id = ?
        ");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->close();


        // Delete applications for the user's jobs
        $stmt = $conn->prepare("
            DELETE applications
            FROM applications
            INNER JOIN jobs
                ON applications.job_id = jobs.id
            WHERE jobs.employer_id = ?
        ");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->close();


        // Delete user's job postings
        $stmt = $conn->prepare("
            DELETE FROM jobs
            WHERE employer_id = ?
        ");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->close();


        // Delete user's messages
        $stmt = $conn->prepare("
            DELETE FROM messages
            WHERE sender_id = ?
               OR receiver_id = ?
        ");
        $stmt->bind_param("ii", $user_id, $user_id);
        $stmt->execute();
        $stmt->close();


        // Delete account
        $stmt = $conn->prepare("
            DELETE FROM users
            WHERE id = ?
        ");
        $stmt->bind_param("i", $user_id);

        if ($stmt->execute()) {

            session_unset();
            session_destroy();

            header("Location: index.php?deleted=1");
            exit();

        } else {

            $error = "Unable to delete account.";
        }

        $stmt->close();
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0">

    <title>Settings - Jobske</title>

    <link
        rel="stylesheet"
        href="style.css">

</head>


<body>

<?php include 'sidebar.php'; ?>


<main class="main-content">

    <section class="settings-page">

        <h1>Settings</h1>


        <!-- ================= LOGOUT ================= -->

        <div class="settings-option">

            <h2>Logout</h2>

            <p>
                Sign out of your Jobske account.
            </p>

            <a
                href="index.php"
                class="btn btn-secondary">

                Logout

            </a>

        </div>


        <!-- ================= DELETE ACCOUNT ================= -->

        <div class="settings-option">

            <h2>Delete Account</h2>

            <p>
                Permanently delete your Jobske account
                and your associated data.
            </p>

            <button
                type="button"
                class="btn btn-danger"
                id="deleteAccountBtn">

                Delete Account

            </button>

        </div>


    </section>

</main>


<!-- ================= DELETE CONFIRMATION ================= -->

<div
    class="modal-overlay"
    id="deleteModal"
    hidden>

    <div class="modal-box">

        <h2>
            Delete Account?
        </h2>

        <p>
            Are you sure you want to permanently
            delete your account?
        </p>

        <div class="modal-actions">

            <button
                type="button"
                class="btn btn-secondary"
                id="cancelDeleteBtn">

                Cancel

            </button>


            <form
                method="POST"
                action="settings.php">

                <input
                    type="hidden"
                    name="action"
                    value="delete_account">

                <button
                    type="submit"
                    class="btn btn-danger">

                    Yes, Delete Account

                </button>

            </form>

        </div>

    </div>

</div>


<script>

document.addEventListener(
    "DOMContentLoaded",
    () => {

        const deleteButton =
            document.getElementById(
                "deleteAccountBtn"
            );

        const deleteModal =
            document.getElementById(
                "deleteModal"
            );

        const cancelButton =
            document.getElementById(
                "cancelDeleteBtn"
            );


        deleteButton.addEventListener(
            "click",
            () => {

                deleteModal.hidden = false;

            }
        );


        cancelButton.addEventListener(
            "click",
            () => {

                deleteModal.hidden = true;

            }
        );

    }
);

</script>

</body>

</html>