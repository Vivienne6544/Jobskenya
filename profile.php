<?php
session_start();
require "db.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$current_user_id = (int) $_SESSION['user_id'];

include 'cities.php';


/* =====================================================
   API: POST REQUESTS
   ===================================================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    header('Content-Type: application/json');

    $action = $_POST['action'] ?? '';


    /* ================= UPDATE PROFILE ================= */

    if ($action === 'update_profile') {

        $name = trim($_POST['name'] ?? '');
        $bio = trim($_POST['bio'] ?? '');

        if ($name === '') {
            echo json_encode(["success" => false, "error" => "Name is required."]);
            exit();
        }

        $stmt = $conn->prepare("UPDATE users SET name = ?, bio = ? WHERE id = ?");
        $stmt->bind_param("ssi", $name, $bio, $current_user_id);

        if ($stmt->execute()) {
            $_SESSION['user_name'] = $name;
            echo json_encode(["success" => true]);
        } else {
            echo json_encode(["success" => false, "error" => "Could not update profile."]);
        }

        $stmt->close();
        exit();
    }

    /* ================= UPDATE PROFILE PICTURE ================= */

    if ($action === 'update_picture') {

        $picture = $_POST['picture'] ?? '';

        if ($picture === '') {
            echo json_encode(["success" => false, "error" => "No picture provided."]);
            exit();
        }

        $stmt = $conn->prepare("UPDATE users SET profile_picture = ? WHERE id = ?");
        $stmt->bind_param("si", $picture, $current_user_id);

        if ($stmt->execute()) {
            echo json_encode(["success" => true]);
        } else {
            echo json_encode(["success" => false, "error" => "Could not update picture."]);
        }

        $stmt->close();
        exit();
    }


    echo json_encode(["success" => false, "error" => "Invalid request."]);
    exit();
}


/* =====================================================
   DETERMINE PROFILE BEING VIEWED
   ===================================================== */

$viewed_user_id = isset($_GET['user_id']) ? (int) $_GET['user_id'] : $current_user_id;
$is_own_profile = ($viewed_user_id === $current_user_id);


/* =====================================================
   GET USER PROFILE
   ===================================================== */

$stmt = $conn->prepare("SELECT id, name, email, bio, profile_picture FROM users WHERE id = ?");
$stmt->bind_param("i", $viewed_user_id);
$stmt->execute();
$profile = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$profile) {
    die("Profile not found.");
}

/* =====================================================
   GET USER'S JOB/SERVICE POSTINGS
   ===================================================== */

$stmt = $conn->prepare("
    SELECT
        jobs.id, jobs.title, jobs.description, jobs.location, jobs.area,
        jobs.pay, jobs.schedule, jobs.created_at,
        (SELECT COUNT(*) FROM applications WHERE applications.job_id = jobs.id) AS applicant_count,
        (SELECT COUNT(*) FROM applications WHERE applications.job_id = jobs.id AND applications.employee_id = ?) AS has_applied
    FROM jobs
    WHERE jobs.employer_id = ?
    ORDER BY jobs.created_at DESC
");
$stmt->bind_param("ii", $current_user_id, $viewed_user_id);
$stmt->execute();
$result = $stmt->get_result();

$jobs = [];
while ($row = $result->fetch_assoc()) {
    $jobs[] = $row;
}
$stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($profile['name']) ?> - Jobske</title>
    <link rel="stylesheet" href="style.css">
</head>

<body>

<?php include 'sidebar.php'; ?>

<main class="main-content">

    <!-- =================================================
         PROFILE HEADER
         ================================================= -->

    <section class="profile-header">

        <div class="avatar-wrapper" id="avatarWrapper">

            <div class="profile-avatar" id="profileAvatar">
                <?php if (!empty($profile['profile_picture'])): ?>
                    <img src="<?= htmlspecialchars($profile['profile_picture']) ?>" alt="Profile picture" id="avatarImg">
                <?php else: ?>
                    <span id="avatarInitial"><?= htmlspecialchars(strtoupper(substr($profile['name'], 0, 1))) ?></span>
                <?php endif; ?>
            </div>

            <?php if ($is_own_profile): ?>
                <input type="file" id="profilePictureInput" accept="image/*" hidden>
                <button type="button" class="camera-badge" id="cameraBadgeBtn" title="Change photo">📷</button>
            <?php endif; ?>

        </div>


        <div class="profile-info">
            <h1><?= htmlspecialchars($profile['name']) ?></h1>
            <?php if (!empty($profile['bio'])): ?>
                <p><?= nl2br(htmlspecialchars($profile['bio'])) ?></p>
            <?php endif; ?>
        </div>


        <div class="profile-actions">
            <?php if ($is_own_profile): ?>
                <button type="button" class="btn btn-primary" id="editProfileBtn">Edit Profile</button>
            <?php else: ?>
                <a href="messages.php?user_id=<?= $viewed_user_id ?>" class="btn btn-primary">Message</a>
            <?php endif; ?>
        </div>

    </section>


    <!-- =================================================
         PHOTO PREVIEW (LIGHTBOX)
         ================================================= -->

    <?php if (!empty($profile['profile_picture'])): ?>
        <div class="modal-overlay" id="photoPreviewModal" hidden>
            <div class="photo-preview-box">
                <img src="<?= htmlspecialchars($profile['profile_picture']) ?>" alt="Profile picture preview">
            </div>
        </div>
    <?php endif; ?>


    <!-- =================================================
         EDIT PROFILE MODAL
         ================================================= -->

    <?php if ($is_own_profile): ?>

        <div class="modal-overlay" id="editProfileModal" hidden>
            <div class="modal-box">

                <button type="button" class="modal-close" id="closeEditProfileBtn">×</button>

                <h2>Edit Profile</h2>

                <form id="editProfileForm">
                    <label for="editName">Name</label>
                    <input type="text" id="editName" value="<?= htmlspecialchars($profile['name']) ?>" required>

                    <label for="editBio">Bio</label>
                    <textarea id="editBio" rows="4"><?= htmlspecialchars($profile['bio'] ?? '') ?></textarea>

                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </form>

            </div>
        </div>

    <?php endif; ?>


    <!-- =================================================
         JOB/SERVICE POSTINGS
         ================================================= -->

    <section class="profile-postings">

        <h2><?= $is_own_profile ? 'My Postings' : 'Postings' ?></h2>

        <?php include 'job.php'; ?>

    </section>

</main>


<script>
document.addEventListener("DOMContentLoaded", () => {

    // ===============================
    // Profile picture: expand preview vs camera upload (split behavior)
    // ===============================
    const profileAvatar = document.getElementById("profileAvatar");
    const photoPreviewModal = document.getElementById("photoPreviewModal");
    const cameraBadgeBtn = document.getElementById("cameraBadgeBtn");
    const profilePictureInput = document.getElementById("profilePictureInput");

    if (profileAvatar && photoPreviewModal) {
        profileAvatar.addEventListener("click", () => {
            photoPreviewModal.hidden = false;
        });

        photoPreviewModal.addEventListener("click", (e) => {
            if (e.target === photoPreviewModal) {
                photoPreviewModal.hidden = true;
            }
        });

        document.addEventListener("keydown", (e) => {
            if (e.key === "Escape" && !photoPreviewModal.hidden) {
                photoPreviewModal.hidden = true;
            }
        });
    }

    if (cameraBadgeBtn && profilePictureInput) {
        cameraBadgeBtn.addEventListener("click", (e) => {
            e.stopPropagation(); // don't also trigger the expand-preview click
            profilePictureInput.click();
        });

        profilePictureInput.addEventListener("change", async () => {
            const file = profilePictureInput.files[0];
            if (!file) return;

            const reader = new FileReader();

            reader.onload = async () => {
                const body = new URLSearchParams();
                body.append("action", "update_picture");
                body.append("picture", reader.result);

                try {
                    const response = await fetch("profile.php", { method: "POST", body });
                    const data = await response.json();

                    if (data.success) {
                        location.reload();
                    } else {
                        alert(data.error || "Could not update picture.");
                    }
                } catch (error) {
                    alert("Something went wrong.");
                }
            };

            reader.readAsDataURL(file);
        });
    }

    // ===============================
    // Edit Profile modal (click-outside + Escape)
    // ===============================
    const editProfileBtn = document.getElementById("editProfileBtn");
    const editProfileModal = document.getElementById("editProfileModal");
    const closeEditProfileBtn = document.getElementById("closeEditProfileBtn");
    const editProfileForm = document.getElementById("editProfileForm");

    if (editProfileBtn) {
        editProfileBtn.addEventListener("click", () => {
            editProfileModal.hidden = false;
        });
    }

    if (closeEditProfileBtn) {
        closeEditProfileBtn.addEventListener("click", () => {
            editProfileModal.hidden = true;
        });
    }

    if (editProfileModal) {
        editProfileModal.addEventListener("click", (e) => {
            if (e.target === editProfileModal) {
                editProfileModal.hidden = true;
            }
        });
    }

    document.addEventListener("keydown", (e) => {
        if (e.key === "Escape" && editProfileModal && !editProfileModal.hidden) {
            editProfileModal.hidden = true;
        }
    });

    if (editProfileForm) {
        editProfileForm.addEventListener("submit", async (event) => {
            event.preventDefault();

            const body = new URLSearchParams();
            body.append("action", "update_profile");
            body.append("name", document.getElementById("editName").value.trim());
            body.append("bio", document.getElementById("editBio").value.trim());

            try {
                const response = await fetch("profile.php", { method: "POST", body });
                const data = await response.json();

                if (data.success) {
                    location.reload();
                } else {
                    alert(data.error || "Could not update profile.");
                }
            } catch (error) {
                alert("Something went wrong.");
            }
        });
    }

});
</script>

</body>
</html>