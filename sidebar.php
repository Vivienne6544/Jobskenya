<aside class="sidebar">

  <div class="sidebar-logo">
    <h2>Jobske</h2>
  </div>

  <nav class="sidebar-nav">
    <!-- Home -->
    <a href="home.php" class="sidebar-link">
      <span class="icon-wrapper"><svg xmlns="http://www.w3.org/2000/svg" fill="none" 
           viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" 
           class="icon">
        <path stroke-linecap="round" stroke-linejoin="round" 
              d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 
              1.125 1.125H9.75v-4.875c0-.621.504-1.125 
              1.125-1.125h2.25c.621 0 1.125.504 
              1.125 1.125V21h4.125c.621 0 1.125-.504 
              1.125-1.125V9.75M8.25 21h8.25" />
      </svg></span>
    </a>

    <!-- Messages -->
    <a href="messages.php" class="sidebar-link">
      <span class="icon-wrapper">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" 
           viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" 
           class="icon">
        <path stroke-linecap="round" stroke-linejoin="round" 
              d="M2.25 12.76c0 1.6 1.123 2.994 2.707 3.227 
              1.068.157 2.148.279 3.238.364.466.037.893.281 
              1.153.671L12 21l2.652-3.978c.26-.39.687-.634 
              1.153-.67 1.09-.086 2.17-.208 3.238-.365 
              1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 
              48.394 0 0 0 12 3c-2.392 0-4.744.175-7.043.513C3.373 
              3.746 2.25 5.14 2.25 6.741v6.018Z" />
      </svg>
     <span class="badge" id="msgBadge" hidden>0</span>
    </span>
    </a>

    <!-- Profile -->
    <a href="profile.php" class="sidebar-link">
      <span class="icon-wrapper">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" 
           viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" 
           class="icon">
        <path stroke-linecap="round" stroke-linejoin="round" 
              d="M15.75 6a3.75 3.75 0 1 1-7.5 0 
              3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 
              7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 
              12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
      </svg>
     <span class="badge" id="applicantBadge" hidden>0</span>
    </span>
    </a>

    <!-- Settings -->
    <a href="settings.php" class="sidebar-link">
       <span class="icon-wrapper"><svg xmlns="http://www.w3.org/2000/svg" fill="none" 
           viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" 
           class="icon">
        <path stroke-linecap="round" stroke-linejoin="round" 
              d="M9.594 3.94c.09-.542.56-.94 
              1.11-.94h2.593c.55 0 1.02.398 
              1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 
              1.075.124l1.217-.456a1.125 1.125 0 0 1 
              1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 
              1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 
              7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 
              1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 
              6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 
              1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 
              0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 
              6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 
              1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 
              1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 
              6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 
              1.125 0 0 1-.26-1.43l1.297-2.247a1.125 
              1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 
              1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z" />
        <path stroke-linecap="round" stroke-linejoin="round" 
              d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
      </svg> </span>
    </a>
  </nav>
  <script>
document.addEventListener("DOMContentLoaded", () => {
    async function updateBadges() {
        try {
            const res = await fetch("notifications.php?action=unread_count");
            const data = await res.json();
            if (!data.success) return;

            const msgBadge = document.getElementById("msgBadge");
            const applicantBadge = document.getElementById("applicantBadge");

            if (data.messages > 0) {
                msgBadge.textContent = data.messages;
                msgBadge.hidden = false;
            } else {
                msgBadge.hidden = true;
            }

            if (data.applicants > 0) {
                applicantBadge.textContent = data.applicants;
                applicantBadge.hidden = false;
            } else {
                applicantBadge.hidden = true;
            }
        } catch (e) {}
    }

    updateBadges();
});
</script>
</aside>
