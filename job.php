<?php
// ===================== job.php — shared job card logic =====================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require "db.php";



if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$current_user_id = (int) $_SESSION['user_id'];


/* =====================================================
   API: POST REQUESTS
   ===================================================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    header('Content-Type: application/json');

    $action = $_POST['action'] ?? '';


    /* ----------------- APPLY / BOOK ----------------- */

    if ($action === 'apply') {

        $job_id = (int) ($_POST['job_id'] ?? 0);

        if (!$job_id) {
            echo json_encode(["success" => false, "error" => "job_id is required."]);
            exit();
        }

        // Don't allow applying to your own posting
      $check = $conn->prepare("SELECT employer_id, title FROM jobs WHERE id = ?");
        $check->bind_param("i", $job_id);
        $check->execute();
        $jobRow = $check->get_result()->fetch_assoc();
        $check->close();

        if (!$jobRow) {
            echo json_encode(["success" => false, "error" => "Job not found."]);
            exit();
        }

        if ((int)$jobRow['employer_id'] === $current_user_id) {
            echo json_encode(["success" => false, "error" => "You can't apply to your own posting."]);
            exit();
        }

        $stmt = $conn->prepare("INSERT INTO applications (job_id, employee_id) VALUES (?, ?)");
        $stmt->bind_param("ii", $job_id, $current_user_id);

 if ($stmt->execute()) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    echo json_encode(["success" => true]);
    flush();

    require_once "push_trigger.php";

$applicant_name = $_SESSION['username'] ?? 'Someone';
triggerPushAsync(
    (int)$jobRow['employer_id'],
    "New applicant",
    "$applicant_name applied to \"{$jobRow['title']}\"",
    "/Jobskenewversion/job.php?job_id=$job_id"
);
} 
        else {
            if ($conn->errno === 1062) {
                echo json_encode(["success" => false, "error" => "You've already applied to this."]);
            } else {
                echo json_encode(["success" => false, "error" => "Could not apply."]);
            }
        }

        $stmt->close();
        exit();
    }


    /* ----------------- DELETE JOB ----------------- */

    if ($action === 'delete_job') {

        $job_id = (int) ($_POST['job_id'] ?? 0);

        if (!$job_id) {
            echo json_encode(["success" => false, "error" => "Invalid job."]);
            exit();
        }

        $stmt = $conn->prepare("DELETE FROM jobs WHERE id = ? AND employer_id = ?");
        $stmt->bind_param("ii", $job_id, $current_user_id);

        if ($stmt->execute() && $stmt->affected_rows > 0) {
            echo json_encode(["success" => true]);
        } else {
            echo json_encode(["success" => false, "error" => "Could not delete posting."]);
        }

        $stmt->close();
        exit();
    }


    /* ----------------- EDIT JOB ----------------- */

    if ($action === 'edit_job') {

        $job_id = (int) ($_POST['job_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $location = trim($_POST['location'] ?? '');
                $area = trim($_POST['area'] ?? '');
        $pay = trim($_POST['pay'] ?? '');
        $schedule = trim($_POST['schedule'] ?? '');

        if (!$job_id || $title === '' || $description === '' || $location === '') {
            echo json_encode(["success" => false, "error" => "Job ID, title, description, and city are required."]);
            exit();
        }

              $stmt = $conn->prepare("
            UPDATE jobs
            SET title = ?, description = ?, location = ?, area = ?, pay = ?, schedule = ?
            WHERE id = ? AND employer_id = ?
        ");
        $stmt->bind_param("ssssssii", $title, $description, $location, $area, $pay, $schedule, $job_id, $current_user_id);

        if ($stmt->execute()) {
            echo json_encode(["success" => true]);
        } else {
            echo json_encode(["success" => false, "error" => "Could not update posting."]);
        }

        $stmt->close();
        exit();
    }


    echo json_encode(["success" => false, "error" => "Invalid request."]);
    exit();
}


/* =====================================================
   API: GET APPLICANTS
   ===================================================== */

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'applicants') {

    header('Content-Type: application/json');

    $job_id = (int) ($_GET['job_id'] ?? 0);

    if (!$job_id) {
        echo json_encode(["success" => false, "error" => "Invalid job."]);
        exit();
    }

    // Only the owner of the job can see its applicants
    $stmt = $conn->prepare("
        SELECT applications.id, applications.employee_id, applications.status, users.name
        FROM applications
        JOIN users ON applications.employee_id = users.id
        JOIN jobs ON applications.job_id = jobs.id
        WHERE applications.job_id = ? AND jobs.employer_id = ?
        ORDER BY applications.created_at ASC
    ");
    $stmt->bind_param("ii", $job_id, $current_user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $applicants = [];
    while ($row = $result->fetch_assoc()) {
        $applicants[] = $row;
    }

    // Mark these applications as read now that the employer is viewing them
$markRead = $conn->prepare("
    UPDATE applications
    SET is_read = 1
    WHERE job_id = ?
");
$markRead->bind_param("i", $job_id);
$markRead->execute();
$markRead->close();

    echo json_encode(["success" => true, "applicants" => $applicants]);
    $stmt->close();
    exit();
}
?>


<!-- ===================== Apply/Book Confirmation Modal ===================== -->
<div class="modal-overlay" id="applyModal" hidden>
    <div class="modal-box" id="applyModalBox">
        <p id="applyModalText">Apply for this job?</p>
        <div class="modal-actions">
            <button class="btn btn-secondary" id="applyCancelBtn">Cancel</button>
            <button class="btn btn-primary" id="applyConfirmBtn">OK</button>
        </div>
    </div>
</div>

<!-- ===================== Delete Job Confirmation Modal ===================== -->
<div class="modal-overlay" id="deleteJobModal" hidden>
    <div class="modal-box">
        <p>Are you sure you want to delete this posting?</p>
        <div class="modal-actions">
            <button class="btn btn-secondary" id="deleteJobCancelBtn">Cancel</button>
            <button class="btn btn-danger" id="deleteJobConfirmBtn">Delete</button>
        </div>
    </div>
</div>

<!-- ===================== Job Card Template (own-profile / other-profile view) ===================== -->
<?php if (isset($jobs)): ?>

    <?php if (empty($jobs)): ?>

        <p class="empty-state">No job postings available.</p>

    <?php else: ?>

        <div class="job-list">

            <?php foreach ($jobs as $job): ?>

                <article class="job-card">

                    <div class="job-card-title"><?= htmlspecialchars($job['title']) ?></div>

                    <div class="job-card-description"><?= nl2br(htmlspecialchars($job['description'])) ?></div>

                    <div class="job-card-meta">
                        <?php if (!empty($job['location'])): ?>
                            <?= htmlspecialchars($job['location']) ?>
                        <?php endif; ?>

                        <?php if (!empty($job['area'])): ?>
                            , <?= htmlspecialchars($job['area']) ?>
                        <?php endif; ?>

                        <?php if (!empty($job['pay'])): ?>
                            • <?= htmlspecialchars($job['pay']) ?>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($job['schedule'])): ?>
                        <div class="job-card-meta"><?= htmlspecialchars($job['schedule']) ?></div>
                    <?php endif; ?>

                    <?php if ($is_own_profile): ?>

                        <div class="job-card-actions">

                            <?php if ((int)$job['applicant_count'] > 0): ?>
                                <div class="field-wrapper" style="display:inline-block;">
                                    <button type="button" class="btn btn-secondary view-applicants-btn"
                                        data-job-id="<?= $job['id'] ?>"
                                        data-job-title="<?= htmlspecialchars($job['title'], ENT_QUOTES) ?>">
                                        View Applicants (<?= $job['applicant_count'] ?>)
                                    </button>
                                    <div class="tip-popup" data-tip-for="view-applicants-<?= $job['id'] ?>" hidden>
                                        Message applicants directly from their profile to move forward.
                                    </div>
                                </div>
                            <?php endif; ?>

                            <button type="button" class="btn btn-secondary edit-job-btn" data-job-id="<?= $job['id'] ?>">Edit</button>
                            <button type="button" class="btn btn-danger delete-job-btn" data-job-id="<?= $job['id'] ?>">Delete Posting</button>

                        </div>

                        <div class="modal-overlay edit-job-modal" id="editJobModal-<?= $job['id'] ?>" hidden>
                            <div class="modal-box">
                                <button type="button" class="modal-close edit-job-close" data-job-id="<?= $job['id'] ?>">×</button>

                                <form class="edit-job-form" data-job-id="<?= $job['id'] ?>" id="editJobPanel-<?= $job['id'] ?>">

                                    <label>Job Title</label>
                                    <input type="text" class="edit-title" value="<?= htmlspecialchars($job['title'], ENT_QUOTES) ?>" required>

                                    <label>Description</label>
                                    <textarea class="edit-description" required><?= htmlspecialchars($job['description']) ?></textarea>

                                    <label>City</label>
                                    <input type="text" class="edit-location" list="editCityOptions-<?= $job['id'] ?>" value="<?= htmlspecialchars($job['location'] ?? '', ENT_QUOTES) ?>" required>
                                    <datalist id="editCityOptions-<?= $job['id'] ?>">
                                        <?php foreach ($kenyan_cities as $city): ?>
                                            <option value="<?= htmlspecialchars($city) ?>">
                                        <?php endforeach; ?>
                                    </datalist>

                                    <label>Specific Area (optional)</label>
                                    <input type="text" class="edit-area" value="<?= htmlspecialchars($job['area'] ?? '', ENT_QUOTES) ?>">

                                    <label>Pay</label>
                                    <input type="text" class="edit-pay" value="<?= htmlspecialchars($job['pay'] ?? '', ENT_QUOTES) ?>">

                                    <label>Schedule</label>
                                    <input type="text" class="edit-schedule" value="<?= htmlspecialchars($job['schedule'] ?? '', ENT_QUOTES) ?>">

                                    <button type="submit" class="btn btn-primary">Save Changes</button>
                                </form>
                            </div>
                        </div>

                    <?php else: ?>

                        <div class="job-card-actions">
                            <?php if ((int)$job['has_applied'] > 0): ?>
                                <button type="button" class="btn btn-secondary" disabled>Already Applied</button>
                            <?php else: ?>
                                <button type="button" class="btn btn-primary apply-job-btn"
                                    data-job-id="<?= $job['id'] ?>"
                                    data-job-title="<?= htmlspecialchars($job['title'], ENT_QUOTES) ?>">
                                    Apply
                                </button>
                            <?php endif; ?>
                        </div>

                    <?php endif; ?>

                </article>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

<?php endif; ?>


<!-- ===================== Applicants Panel ===================== -->
<?php if (isset($is_own_profile) && $is_own_profile): ?>

    <div class="modal-overlay" id="applicantsModal" hidden>
        <div class="modal-box applicants-panel">

            <button type="button" class="modal-close" id="closeApplicantsBtn">×</button>

            <h2>Applicants</h2>
            <h3 id="applicantJobTitle"></h3>

            <div id="applicantsList">Loading...</div>

        </div>
    </div>

<?php endif; ?>


<script>
    // ===============================
    // Render jobs (home feed context)
    // ===============================
    function renderJobs(jobs) {
        jobList.innerHTML = "";

        if (jobs.length === 0) {
            emptyState.hidden = false;
            emptyState.textContent = "No listings available right now.";
            return;
        }

        emptyState.hidden = true;

        jobs.forEach(job => {
            const card = document.createElement("div");
            card.className = "job-card";

            const isFilled = job.status === "accepted";
            const isApplied = parseInt(job.has_applied) > 0;
            const isService = job.type === "service"; // TODO (DB): requires `type` column
            const actionLabel = isService ? "Book" : "Apply";
            const appliedLabel = isService ? "Already Booked" : "Already Applied";

                     card.innerHTML = `
                <div class="job-card-title">${escapeHtml(job.title)}</div>
               <div class="job-card-description">${escapeHtml(job.description).replace(/\n/g, "<br>")}</div>
                <div class="job-card-meta">${escapeHtml(job.location || "")}${job.area ? ", " + escapeHtml(job.area) : ""} ${job.pay ? "• " + escapeHtml(job.pay) : ""}</div>
                <div class="job-card-meta">Posted by ${escapeHtml(job.poster_name)}</div>
            `;

            card.addEventListener("click", () => {
                window.location.href = `profile.php?user_id=${job.employer_id}`;
            });

            const applyBtnWrapper = document.createElement("div");
            applyBtnWrapper.className = "field-wrapper";
            applyBtnWrapper.style.marginTop = "10px";

            const applyBtn = document.createElement("button");
            applyBtn.className = "btn btn-primary";
            applyBtn.textContent = isFilled ? "Position Filled" : (isApplied ? appliedLabel : actionLabel);
            applyBtn.disabled = isFilled || isApplied;

            // Recommendation popup: Apply/Book — first-time context
            const applyTip = document.createElement("div");
            applyTip.className = "tip-popup";
            applyTip.hidden = true;
            applyTip.textContent = isService
                ? "Booking sends a request to the provider — they'll be notified and you can message them."
                : "Applying notifies the poster — you can message them directly once applied.";

            applyBtn.addEventListener("click", (e) => {
                e.stopPropagation();
                applyTip.hidden = false;
                openApplyModal(job.id, job.title, actionLabel);
            });

            document.addEventListener("click", (e) => {
                if (!applyTip.hidden && !applyTip.contains(e.target) && e.target !== applyBtn) {
                    applyTip.hidden = true;
                }
            });

            applyBtnWrapper.appendChild(applyBtn);
            applyBtnWrapper.appendChild(applyTip);
            card.appendChild(applyBtnWrapper);
            jobList.appendChild(card);
        });
    }

    // ===============================
    // Escape HTML
    // ===============================
    function escapeHtml(text) {
        const div = document.createElement("div");
        div.textContent = text || "";
        return div.innerHTML;
    }

document.addEventListener("DOMContentLoaded", () => {

    const applyModal = document.getElementById("applyModal");
    const applyModalText = document.getElementById("applyModalText");
    const applyCancelBtn = document.getElementById("applyCancelBtn");
    const applyConfirmBtn = document.getElementById("applyConfirmBtn");

    let pendingApplyJobId = null;
let pendingApplyBtn = null;

    // ===============================
    // Apply/Book confirmation modal (click-outside + Escape)
    // ===============================
  window.openApplyModal = function(jobId, jobTitle, actionLabel, btn) {
    pendingApplyJobId = jobId;
    pendingApplyBtn = btn;
    applyModalText.textContent = `${actionLabel} for "${jobTitle}"?`;
    applyModal.hidden = false;
}

document.querySelectorAll(".apply-job-btn").forEach(button => {
    button.addEventListener("click", () => {
        window.openApplyModal(button.dataset.jobId, button.dataset.jobTitle, "Apply", button);
    });
});

    if (applyCancelBtn) {
        applyCancelBtn.addEventListener("click", () => {
            applyModal.hidden = true;
            pendingApplyJobId = null;
        });
    }

    if (applyConfirmBtn) {
        applyConfirmBtn.addEventListener("click", async () => {
            if (!pendingApplyJobId) return;

            const body = new URLSearchParams();
            body.append("action", "apply");
            body.append("job_id", pendingApplyJobId);

            const response = await fetch("job.php", { method: "POST", body });
            const data = await response.json();

            applyModal.hidden = true;
            pendingApplyJobId = null;
if (data.success) {
    applyModal.hidden = true;
    pendingApplyJobId = null;
    if (typeof loadJobs === "function") {
        loadJobs();
    } else {
        location.reload();
    }
} else {
    alert(data.error || "Could not apply.");
}
        });
    }

    applyModal.addEventListener("click", (e) => {
        if (e.target === applyModal) {
            applyModal.hidden = true;
            pendingApplyJobId = null;
        }
    });

    document.addEventListener("keydown", (e) => {
        if (e.key === "Escape" && !applyModal.hidden) {
            applyModal.hidden = true;
            pendingApplyJobId = null;
        }
    });

    // ===============================
    // Delete posting
    // ===============================
const deleteJobModal = document.getElementById("deleteJobModal");
const deleteJobCancelBtn = document.getElementById("deleteJobCancelBtn");
const deleteJobConfirmBtn = document.getElementById("deleteJobConfirmBtn");
let pendingDeleteJobId = null;

document.querySelectorAll(".delete-job-btn").forEach(button => {
    button.addEventListener("click", () => {
        pendingDeleteJobId = button.dataset.jobId;
        deleteJobModal.hidden = false;
    });
});

deleteJobCancelBtn.addEventListener("click", () => {
    deleteJobModal.hidden = true;
    pendingDeleteJobId = null;
});

deleteJobModal.addEventListener("click", (e) => {
    if (e.target === deleteJobModal) {
        deleteJobModal.hidden = true;
        pendingDeleteJobId = null;
    }
});

deleteJobConfirmBtn.addEventListener("click", async () => {
    if (!pendingDeleteJobId) return;

    const body = new URLSearchParams();
    body.append("action", "delete_job");
    body.append("job_id", pendingDeleteJobId);

    try {
        const response = await fetch("job.php", { method: "POST", body });
        const data = await response.json();
        if (data.success) {
            location.reload();
        } else {
            alert(data.error || "Could not delete posting.");
        }
    } catch (error) {
        alert("Something went wrong.");
    }

    deleteJobModal.hidden = true;
    pendingDeleteJobId = null;
});
    // ===============================
    // Edit job (now a modal — click-outside + Escape)
    // ===============================
    document.querySelectorAll(".edit-job-btn").forEach(button => {
        button.addEventListener("click", () => {
            const jobId = button.dataset.jobId;
            const modal = document.getElementById(`editJobModal-${jobId}`);
            if (modal) modal.hidden = false;
        });
    });

    document.querySelectorAll(".edit-job-close").forEach(button => {
        button.addEventListener("click", () => {
            const jobId = button.dataset.jobId;
            const modal = document.getElementById(`editJobModal-${jobId}`);
            if (modal) modal.hidden = true;
        });
    });

    document.querySelectorAll(".edit-job-modal").forEach(modal => {
        modal.addEventListener("click", (e) => {
            if (e.target === modal) modal.hidden = true;
        });
    });

    document.addEventListener("keydown", (e) => {
        if (e.key === "Escape") {
            document.querySelectorAll(".edit-job-modal").forEach(modal => {
                if (!modal.hidden) modal.hidden = true;
            });
        }
    });

    document.querySelectorAll(".edit-job-form").forEach(form => {
        form.addEventListener("submit", async (event) => {
            event.preventDefault();

            const jobId = form.dataset.jobId;

            const body = new URLSearchParams();
            body.append("action", "edit_job");
            body.append("job_id", jobId);
            body.append("title", form.querySelector(".edit-title").value.trim());
            body.append("description", form.querySelector(".edit-description").value.trim());
            body.append("location", form.querySelector(".edit-location").value.trim());
                        body.append("area", form.querySelector(".edit-area").value.trim());
            body.append("pay", form.querySelector(".edit-pay").value.trim());
            body.append("schedule", form.querySelector(".edit-schedule").value.trim());

            try {
                const response = await fetch("job.php", { method: "POST", body });
                const data = await response.json();

                if (data.success) {
                    location.reload();
                } else {
                    alert(data.error || "Could not update posting.");
                }
            } catch (error) {
                alert("Something went wrong.");
            }
        });
    });

    // ===============================
    // Applicants panel (click-outside + Escape)
    // ===============================
    const applicantsModal = document.getElementById("applicantsModal");
    const applicantsList = document.getElementById("applicantsList");
    const applicantJobTitle = document.getElementById("applicantJobTitle");
    const closeApplicantsBtn = document.getElementById("closeApplicantsBtn");

    document.querySelectorAll(".view-applicants-btn").forEach(button => {
        button.addEventListener("click", async (e) => {

            // Recommendation popup: View Applicants
            const jobId = button.dataset.jobId;
            const tip = document.querySelector(`[data-tip-for="view-applicants-${jobId}"]`);
            if (tip) {
                tip.hidden = false;
                document.addEventListener("click", function closeTip(ev) {
                    if (!tip.contains(ev.target) && ev.target !== button) {
                        tip.hidden = true;
                        document.removeEventListener("click", closeTip);
                    }
                });
            }

            applicantsModal.hidden = false;
            applicantJobTitle.textContent = button.dataset.jobTitle;
            applicantsList.innerHTML = "Loading...";

            try {
                const response = await fetch(`job.php?action=applicants&job_id=${jobId}`);
                const data = await response.json();

                if (!data.success) {
                    applicantsList.innerHTML = `<p>${escapeHtml(data.error)}</p>`;
                    return;
                }

                if (data.applicants.length === 0) {
                    applicantsList.innerHTML = "<p>No applicants yet.</p>";
                    return;
                }

                applicantsList.innerHTML = "";

                data.applicants.forEach(applicant => {
                    const card = document.createElement("div");
                    card.className = "applicant-card";

                    const name = escapeHtml(applicant.name);

                    card.innerHTML = `
                        <div class="applicant-name">${name}</div>
                        <div class="applicant-actions">
                            <a href="profile.php?user_id=${applicant.employee_id}" class="btn btn-secondary">
                                View Profile
                            </a>
                        </div>
                    `;

                    applicantsList.appendChild(card);
                });

            } catch (error) {
                applicantsList.innerHTML = "<p>Could not load applicants.</p>";
            }
        });
    });

    if (closeApplicantsBtn) {
        closeApplicantsBtn.addEventListener("click", () => {
            applicantsModal.hidden = true;
        });
    }
if (applicantsModal) {
    applicantsModal.addEventListener("click", (e) => {
        if (e.target === applicantsModal) {
            applicantsModal.hidden = true;
        }
    });

    document.addEventListener("keydown", (e) => {
        if (e.key === "Escape" && !applicantsModal.hidden) {
            applicantsModal.hidden = true;
        }
    });
} 
 });
</script>