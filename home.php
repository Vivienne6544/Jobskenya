<?php
// ===================== Home (Page + API, merged) =====================
session_start();
require "db.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$current_user_id = $_SESSION['user_id'];

include 'cities.php';

// =====================================================
// JSON API MODE
// =====================================================
$isApiRequest =
    (isset($_GET['action']) && $_GET['action'] === 'get') ||
    $_SERVER['REQUEST_METHOD'] === 'POST';

if ($isApiRequest) {
    header('Content-Type: application/json');

    // ----------------- GET: job/service listings (search + filters) -----------------
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get') {

        $keyword = trim($_GET['keyword'] ?? '');
        $city = trim($_GET['city'] ?? '');
        $type = trim($_GET['type'] ?? 'job'); // TODO (DB): requires `type` column on jobs table (job/service)

        $conditions = [];
        $params = [];
        $types = "";

        // TODO (DB): re-enable once `type` column exists
        $conditions[] = "type = ?";
         $params[] = $type;
         $types .= "s";

        if ($city !== "") {
            $conditions[] = "location = ?";
            $params[] = $city;
            $types .= "s";
        }

        $where = count($conditions) > 0 ? "WHERE " . implode(" AND ", $conditions) : "";

        // TODO (DB): requires FULLTEXT index — ALTER TABLE jobs ADD FULLTEXT(title, description);
        // Falling back to LIKE search until the index is added in phpMyAdmin.
        if ($keyword !== "") {
            $keywordCondition = "(title LIKE ? OR description LIKE ?)";
            $conditions[] = $keywordCondition;
            $likeTerm = "%$keyword%";
            $params[] = $likeTerm;
            $params[] = $likeTerm;
            $types .= "ss";
            $where = "WHERE " . implode(" AND ", $conditions);
        }

      // has_applied must be a ? not $current_user_id
$stmt = $conn->prepare("
    SELECT jobs.*, users.name AS poster_name,
        (SELECT COUNT(*) FROM applications WHERE applications.job_id = jobs.id) AS applicant_count,
        (SELECT COUNT(*) FROM applications WHERE applications.job_id = jobs.id AND applications.employee_id = ?) AS has_applied
    FROM jobs
    JOIN users ON jobs.employer_id = users.id
    $where
    ORDER BY jobs.created_at DESC
");

array_unshift($params, $current_user_id);
$types = "i" . $types;
$stmt->bind_param($types, ...$params);

        $stmt->execute();
        $result = $stmt->get_result();

        $jobs = [];
        while ($row = $result->fetch_assoc()) {
            $jobs[] = $row;
        }

        // ----------------- Levenshtein fuzzy re-ranking (keyword typo tolerance) -----------------
             if ($keyword !== "" && count($jobs) > 0) {
            $keywordLower = strtolower($keyword);

            foreach ($jobs as &$job) {
                $combinedText = strtolower($job['title'] . ' ' . $job['description']);
                $words = explode(" ", $combinedText);
                $bestDistance = 999;
                foreach ($words as $word) {
                    $distance = levenshtein($keywordLower, $word);
                    if ($distance < $bestDistance) {
                        $bestDistance = $distance;
                    }
                }
                $job['relevance'] = $bestDistance;
            }
            unset($job);

            // Sort by closeness (lower distance = more relevant), excluding very poor matches
            $jobs = array_filter($jobs, function ($j) {
                return $j['relevance'] <= 4; // threshold — tune later if needed
            });

            usort($jobs, function ($a, $b) {
                return $a['relevance'] <=> $b['relevance'];
            });

            $jobs = array_values($jobs);
        }

        echo json_encode(["success" => true, "jobs" => $jobs]);
        $stmt->close();
        $conn->close();
        exit();
    }

    // ----------------- POST: create a job/service listing -----------------
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {

        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $location = trim($_POST['location'] ?? '');
                $area = trim($_POST['area'] ?? '');
        $pay = trim($_POST['pay'] ?? '');
        $schedule = trim($_POST['schedule'] ?? '');
        $type = trim($_POST['type'] ?? 'job'); // TODO (DB): requires `type` column on jobs table

        if ($title === '' || $description === '') {
            echo json_encode(["success" => false, "error" => "Title and description are required."]);
            exit();
        }

        if ($location === '') {
            echo json_encode(["success" => false, "error" => "Please select a city."]);
            exit();
        }

        // TODO (DB): once `type` column exists, add it to this INSERT
 $stmt = $conn->prepare("
    INSERT INTO jobs (employer_id, title, description, type, location, area, pay, schedule, status)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'open')
");
$stmt->bind_param("isssssss", $current_user_id, $title, $description, $type, $location, $area, $pay, $schedule);

        if ($stmt->execute()) { 
            echo json_encode(["success" => true]);
        } else {
            echo json_encode(["success" => false, "error" => $stmt->error]);
        }

        $stmt->close();
        $conn->close();
        exit();
    }


    echo json_encode(["success" => false, "error" => "Invalid request."]);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Jobske - Home</title>
    <link rel="stylesheet" href="style.css">
</head>

<body>

<?php include 'sidebar.php'; ?>

<main class="main-content">

        <!-- ===================== Tabs ===================== -->
    <div class="tab-bar" id="tabBar">
        <button type="button" class="tab-btn active" id="jobsTabBtn" data-type="job">Jobs</button>
        <button type="button" class="tab-btn" id="servicesTabBtn" data-type="service">Services</button>
    </div>

    <div class="tab-content-panel" id="tabContentPanel">

     <header class="page-header" id="pageHeader" style="display:flex; justify-content:space-between; align-items:flex-start;">
        <div>
            <h1 id="pageHeading">TEST a Job</h1>
            <p id="pageSubheading">Browse available job opportunities.</p>
        </div>
        <div style="text-align:left; min-width:110px;">
            <div style="font-size:15px; color:#555; margin-bottom:6px;">Notifications</div>
            <label class="notif-switch">
                <input type="checkbox" id="notifToggle">
                <span class="notif-slider"></span>
            </label>
        </div>
    </header>

    <button class="btn btn-primary add-job-btn" id="addJobBtn">
        <span class="add-icon">+</span> <span id="addBtnLabel">Add Job</span>
    </button>

    <!-- ===================== Add Job/Service Modal ===================== -->
    <div class="modal-overlay" id="addJobModal" hidden>
        <div class="modal-box add-job-inner" id="addJobPanel">

            <button type="button" class="modal-close" id="closeAddJobBtn">×</button>

            <h2 id="addPanelHeading">Post a job</h2>

            <form id="addJobForm">
               <label id="titleLabel" for="jobTitle">Job Title</label>
                <div class="field-wrapper">
                    <input type="text" id="jobTitle" required>
                    <!-- Recommendation popup: Job Title -->
                    <div class="tip-popup" id="titleTip" hidden>
                        Use a clear, specific title to help people find your posting.
                    </div>
                </div>

                <label for="jobDescription">Description</label>
                <textarea id="jobDescription" rows="4" required></textarea>

                <label for="jobLocationInput">City</label>
                <div class="field-wrapper">
                    <input type="text" id="jobLocationInput" placeholder="Select City" readonly required>
                    <!-- Recommendation popup: Location -->
                    <div class="tip-popup" id="locationTip" hidden>
                        Ensure to use correct spelling to help people identify your location.
                    </div>
                    <!-- Custom filterable dropdown -->
                    <div class="custom-dropdown" id="cityDropdown" hidden>
                        <input type="text" id="citySearchInput" placeholder="Type to search...">
                        <div class="dropdown-list" id="cityDropdownList">
                            <?php foreach ($kenyan_cities as $city): ?>
                                <div class="dropdown-option" data-value="<?= htmlspecialchars($city) ?>">
                                    <?= htmlspecialchars($city) ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
<label for="jobAreaInput">Specific Area (optional)</label>
<input type="text" id="jobAreaInput" placeholder="e.g. Kilimani, near the market">

              <label id="payLabel" for="jobPay">Pay</label>
                <input type="text" id="jobPay" placeholder="e.g. KES 25,000/month">

                <label for="jobSchedule">Schedule</label>
                <input type="text" id="jobSchedule" placeholder="e.g. Monday - Friday">

                <button type="submit" class="btn btn-primary" id="addSubmitBtn">Post Job</button>
            </form>
        </div>
    </div>

    <!-- ===================== Search + Filter Row ===================== -->
    <section class="job-search">
        <input type="text" id="jobSearch" placeholder="Search by keyword...">

        <input type="text" id="locationSearch" placeholder="Search by location...">

        <div class="field-wrapper city-filter-wrapper">
            <input type="text" id="cityFilterInput" placeholder="All Cities" readonly>
            <div class="custom-dropdown" id="cityFilterDropdown" hidden>
                <input type="text" id="cityFilterSearchInput" placeholder="Type to search...">
                <div class="dropdown-list" id="cityFilterDropdownList">
                    <div class="dropdown-option" data-value="">All Cities</div>
                    <?php foreach ($kenyan_cities as $city): ?>
                        <div class="dropdown-option" data-value="<?= htmlspecialchars($city) ?>">
                            <?= htmlspecialchars($city) ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </section>

    <p class="empty-state" id="emptyState">Loading...</p>

    <section id="jobList" class="job-list"></section>

    <?php include 'job.php'; ?>
  </div>
</main>

<script>
const HOME_API = "home.php";
let activeType = "job"; // TODO (DB): drives `type` filter once column exists

// ===== PUSH SETUP =====
const VAPID_PUBLIC_KEY = "BC5JuAqFrl49UCZADtZSoBa9_ZTmYuDuXs7Jghod8rlT6Wu2si56J1muoGnCrXACJl57-Wq4XtLqs4QEvxT51ZY";
function urlBase64ToUint8Array(base64String) {
  const padding = '='.repeat((4 - base64String.length % 4) % 4);
  const base64 = (base64String + padding).replace(/\-/g, '+').replace(/_/g, '/');
  const rawData = atob(base64);
  return Uint8Array.from([...rawData].map(c => c.charCodeAt(0)));
}
async function registerPush() {
  if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;
  const reg = await navigator.serviceWorker.register('sw.js');
  const sub = await reg.pushManager.subscribe({
    userVisibleOnly: true,
    applicationServerKey: urlBase64ToUint8Array(VAPID_PUBLIC_KEY)
  });
  await fetch('subscribe.php', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify(sub)
  });
}

// TODO (future): Web Push registration + subscription-save call goes here,
// triggered once user grants notification permission. Backend endpoint
// and service worker file (sw.js) not yet created.

document.addEventListener("DOMContentLoaded", () => {

    const jobsTabBtn = document.getElementById("jobsTabBtn");
    const servicesTabBtn = document.getElementById("servicesTabBtn");
    const pageHeading = document.getElementById("pageHeading");
    const pageSubheading = document.getElementById("pageSubheading");
    const addBtnLabel = document.getElementById("addBtnLabel");
    const addPanelHeading = document.getElementById("addPanelHeading");
    const addSubmitBtn = document.getElementById("addSubmitBtn");

    const addJobBtn = document.getElementById("addJobBtn");
    const addJobModal = document.getElementById("addJobModal");
    const addJobPanel = document.getElementById("addJobPanel");
    const closeAddJobBtn = document.getElementById("closeAddJobBtn");
    const addJobForm = document.getElementById("addJobForm");

    const jobTitleInput = document.getElementById("jobTitle");
    const titleTip = document.getElementById("titleTip");
    const jobLocationInput = document.getElementById("jobLocationInput");
    const locationTip = document.getElementById("locationTip");

    const jobSearch = document.getElementById("jobSearch");
    const locationSearch = document.getElementById("locationSearch");

    const cityFilterInput = document.getElementById("cityFilterInput");
    const cityFilterDropdown = document.getElementById("cityFilterDropdown");
    const cityFilterSearchInput = document.getElementById("cityFilterSearchInput");
    const cityFilterDropdownList = document.getElementById("cityFilterDropdownList");

    const cityDropdown = document.getElementById("cityDropdown");
    const citySearchInput = document.getElementById("citySearchInput");
    const cityDropdownList = document.getElementById("cityDropdownList");

    const jobList = document.getElementById("jobList");
    const emptyState = document.getElementById("emptyState");

        const notifToggle = document.getElementById("notifToggle");
    notifToggle.checked = localStorage.getItem("jobske_notif") === "on";

    notifToggle.addEventListener("change", async () => {
        if (notifToggle.checked) {
            try {
                if (Notification.permission === "denied") {
                    alert("Notifications are blocked. Enable them in your browser settings.");
                    notifToggle.checked = false;
                    localStorage.setItem("jobske_notif", "off");
                    return;
                }
                if (Notification.permission !== "granted") {
                    const perm = await Notification.requestPermission();
                    if (perm !== "granted") {
                        notifToggle.checked = false;
                        localStorage.setItem("jobske_notif", "off");
                        return;
                    }
                }
                await registerPush();
                localStorage.setItem("jobske_notif", "on");
                console.log("Push enabled");
            } catch (e) {
                console.error(e);
                alert("Could not enable push");
                notifToggle.checked = false;
                localStorage.setItem("jobske_notif", "off");
            }
        } else {
            localStorage.setItem("jobske_notif", "off");
            try {
                const reg = await navigator.serviceWorker.getRegistration();
                if (reg) {
                    const sub = await reg.pushManager.getSubscription();
                    if (sub) await sub.unsubscribe();
                }
                await fetch('unsubscribe.php', {method:'POST'});
            } catch(e){}
        }
    });

    let allJobs = [];
    let selectedCity = "";

    const titleLabel = document.getElementById("titleLabel");
const payLabel = document.getElementById("payLabel");

    // ===============================
    // Restore cached filters (sessionStorage)
    // ===============================
    jobSearch.value = sessionStorage.getItem("jobske_keyword") || "";
    locationSearch.value = sessionStorage.getItem("jobske_locationSearch") || "";
    selectedCity = sessionStorage.getItem("jobske_city") || "";
    cityFilterInput.value = selectedCity || "";
    cityFilterInput.placeholder = selectedCity ? "" : "All Cities";
    if (selectedCity) cityFilterInput.value = selectedCity;

    const cachedType = sessionStorage.getItem("jobske_activeType");
    if (cachedType) {
        activeType = cachedType;
    }

    // ===============================
    // Tabs
    // ===============================
    function setActiveTab(type) {
        activeType = type;
        sessionStorage.setItem("jobske_activeType", type);

        if (type === "job") {
            jobsTabBtn.classList.add("active");
            servicesTabBtn.classList.remove("active");
            pageHeading.textContent = "Find a Job";
            pageSubheading.textContent = "Browse available job opportunities.";
            addBtnLabel.textContent = "Add Job";
            addPanelHeading.textContent = "Post a job";
            addSubmitBtn.textContent = "Post Job";
        } else {
            servicesTabBtn.classList.add("active");
            jobsTabBtn.classList.remove("active");
            pageHeading.textContent = "Find a Service";
            pageSubheading.textContent = "Browse local services near you.";
            addBtnLabel.textContent = "Add Service";
            addPanelHeading.textContent = "Post a service";
            addSubmitBtn.textContent = "Post Service";
        }

        if (type === "service") {
    titleLabel.textContent = "Service Title";
    payLabel.textContent = "Price";
} else {
    titleLabel.textContent = "Job Title";
    payLabel.textContent = "Pay";
}

        loadJobs();
    }

    jobsTabBtn.addEventListener("click", () => setActiveTab("job"));
    servicesTabBtn.addEventListener("click", () => setActiveTab("service"));
    setActiveTab(activeType);

    // ===============================
    // Add Job/Service modal (click-outside + Escape to close)
    // ===============================
    addJobBtn.addEventListener("click", () => {
        addJobModal.hidden = false;
    });

    closeAddJobBtn.addEventListener("click", () => {
        addJobModal.hidden = true;
    });

    addJobModal.addEventListener("click", (e) => {
        if (e.target === addJobModal) {
            addJobModal.hidden = true;
        }
    });

    document.addEventListener("keydown", (e) => {
        if (e.key === "Escape" && !addJobModal.hidden) {
            addJobModal.hidden = true;
        }
    });

    // ===============================
    // Recommendation popups (Job Title / Location) — Add form only
    // ===============================
    jobTitleInput.addEventListener("focus", () => {
        titleTip.hidden = false;
    });
    document.addEventListener("click", (e) => {
        if (!titleTip.hidden && !titleTip.contains(e.target) && e.target !== jobTitleInput) {
            titleTip.hidden = true;
        }
    });

    jobLocationInput.addEventListener("focus", () => {
        locationTip.hidden = false;
    });
    document.addEventListener("click", (e) => {
        if (!locationTip.hidden && !locationTip.contains(e.target) && e.target !== jobLocationInput) {
            locationTip.hidden = true;
        }
    });

    // ===============================
    // Custom filterable dropdown — Add form city picker
    // ===============================
    jobLocationInput.addEventListener("click", () => {
        cityDropdown.hidden = false;
        citySearchInput.focus();
    });

    citySearchInput.addEventListener("input", () => {
        const query = citySearchInput.value.trim().toLowerCase();
        cityDropdownList.querySelectorAll(".dropdown-option").forEach(opt => {
            const match = opt.textContent.toLowerCase().includes(query);
            opt.style.display = match ? "block" : "none";
        });
    });

    cityDropdownList.addEventListener("click", (e) => {
        const option = e.target.closest(".dropdown-option");
        if (!option) return;
        jobLocationInput.value = option.dataset.value;
        cityDropdown.hidden = true;
        citySearchInput.value = "";
        cityDropdownList.querySelectorAll(".dropdown-option").forEach(opt => opt.style.display = "block");
    });

    document.addEventListener("click", (e) => {
        if (!cityDropdown.hidden && !cityDropdown.contains(e.target) && e.target !== jobLocationInput) {
            cityDropdown.hidden = true;
        }
    });

    // ===============================
    // Custom filterable dropdown — City filter (search row)
    // ===============================
    cityFilterInput.addEventListener("click", () => {
        cityFilterDropdown.hidden = false;
        cityFilterSearchInput.focus();
    });

    cityFilterSearchInput.addEventListener("input", () => {
        const query = cityFilterSearchInput.value.trim().toLowerCase();
        cityFilterDropdownList.querySelectorAll(".dropdown-option").forEach(opt => {
            const match = opt.textContent.toLowerCase().includes(query);
            opt.style.display = match ? "block" : "none";
        });
    });

    cityFilterDropdownList.addEventListener("click", (e) => {
        const option = e.target.closest(".dropdown-option");
        if (!option) return;
        selectedCity = option.dataset.value;
        cityFilterInput.value = selectedCity;
        cityFilterInput.placeholder = "All Cities";
        cityFilterDropdown.hidden = true;
        cityFilterSearchInput.value = "";
        cityFilterDropdownList.querySelectorAll(".dropdown-option").forEach(opt => opt.style.display = "block");

        sessionStorage.setItem("jobske_city", selectedCity);
        loadJobs();
    });

    document.addEventListener("click", (e) => {
        if (!cityFilterDropdown.hidden && !cityFilterDropdown.contains(e.target) && e.target !== cityFilterInput) {
            cityFilterDropdown.hidden = true;
        }
    });

    // ===============================
    // Post a job/service
    // ===============================
    addJobForm.addEventListener("submit", async (e) => {
        e.preventDefault();

        const body = new URLSearchParams();
        body.append("action", "add");
        body.append("type", activeType);
        body.append("title", jobTitleInput.value.trim());
        body.append("description", document.getElementById("jobDescription").value.trim());
        body.append("location", jobLocationInput.value.trim());
                body.append("area", document.getElementById("jobAreaInput").value.trim());
        body.append("pay", document.getElementById("jobPay").value.trim());
        body.append("schedule", document.getElementById("jobSchedule").value.trim());

        const response = await fetch(HOME_API, { method: "POST", body });
        const data = await response.json();

        if (data.success) {
            addJobForm.reset();
            addJobModal.hidden = true;
            loadJobs();
        } else {
            alert(data.error || "Could not post.");
        }
    });

    // ===============================
    // Load jobs (server-side: city + keyword via FULLTEXT/Levenshtein)
    // ===============================
    async function loadJobs() {
        emptyState.hidden = false;
        emptyState.textContent = "Loading...";
        jobList.innerHTML = "";

        const params = new URLSearchParams();
        params.append("action", "get");
        params.append("type", activeType);
        if (selectedCity) params.append("city", selectedCity);
        if (jobSearch.value.trim()) params.append("keyword", jobSearch.value.trim());

        try {
            const response = await fetch(`${HOME_API}?${params.toString()}`);
            const data = await response.json();

            if (!data.success) {
                emptyState.textContent = data.error || "Could not load jobs.";
                return;
            }

            allJobs = data.jobs;
            applyLocationSearchFilter();

        } catch (err) {
            emptyState.textContent = "Could not load jobs. Is the server running?";
        }
    }

    window.loadJobs = loadJobs;

    // ===============================
    // Location free-text search (client-side, secondary to city dropdown)
    // ===============================
    function applyLocationSearchFilter() {
        const query = locationSearch.value.trim().toLowerCase();
        const filtered = query
            ? allJobs.filter(job => {
                const locationText = (job.location || "").toLowerCase();
                const areaText = (job.area || "").toLowerCase();
                return locationText.includes(query) || areaText.includes(query);
            })
            : allJobs;
        renderJobs(filtered);
    }

    // ===============================
    // Filters & search — cache to sessionStorage on change
    // ===============================
    jobSearch.addEventListener("input", () => {
        sessionStorage.setItem("jobske_keyword", jobSearch.value);
        loadJobs();
    });

    locationSearch.addEventListener("input", () => {
        sessionStorage.setItem("jobske_locationSearch", locationSearch.value);
        applyLocationSearchFilter();
    });

    loadJobs();

});
</script>

</body>
</html>