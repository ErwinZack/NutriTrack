// notifications.js
// Handles in-app notification display and alert logic

/* Format timestamp to relative time e.g. "2 min ago" */
function timeAgo(dateStr) {
  const diff = Math.floor((Date.now() - new Date(dateStr)) / 1000);
  if (diff < 60) return "Just now";
  if (diff < 3600) return Math.floor(diff / 60) + " min ago";
  if (diff < 86400) return Math.floor(diff / 3600) + " hr ago";
  return Math.floor(diff / 86400) + "d ago";
}

/* Escape HTML to prevent XSS */
function escapeHtml(str) {
  return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
}

/* Get initials from full name e.g. "Erwin Zacarias" -> "EZ" */
function senderInitials(name) {
  const parts = name.trim().split(" ");
  let initials = parts[0].charAt(0).toUpperCase();
  if (parts.length > 1)
    initials += parts[parts.length - 1].charAt(0).toUpperCase();
  return initials;
}

/* Build avatar HTML for dropdown item */
function buildAvatarHtml(senderName, senderImage) {
  if (!senderName) {
    return `<div class="notif-avatar-fallback">🔔</div>`;
  }
  if (senderImage) {
    return `<img src="../../${escapeHtml(senderImage)}" alt="${escapeHtml(senderName)}" class="notif-sender-avatar">`;
  }
  return `<div class="notif-sender-initials">${senderInitials(senderName)}</div>`;
}

/* Render notifications into the dropdown list */
function renderNotifications(items) {
  const list        = document.getElementById("notifList");
  const countBadge  = document.getElementById("notifCountBadge");
  const unreadBadge = document.getElementById("notifUnreadBadge");

  /* Empty state */
  if (!items || items.length === 0) {
    list.innerHTML = `
      <div class="notif-empty">
        <div class="notif-empty-icon">🔔</div>
        No notifications yet
      </div>`;
    countBadge.style.display  = "none";
    unreadBadge.style.display = "none";
    return;
  }

  /* Count unread */
  const unreadCount = items.filter((n) => n.is_read == 0).length;

  if (unreadCount > 0) {
    countBadge.textContent    = unreadCount;
    countBadge.style.display  = "flex";
    unreadBadge.textContent   = unreadCount + " new";
    unreadBadge.style.display = "block";
  } else {
    countBadge.style.display  = "none";
    unreadBadge.style.display = "none";
  }

  /* Build item HTML
     Structure: .notif-item-wrap
       ├── a.notif-item (avatar + text)
       └── .notif-item-right (3-dot + read dot) — same as page items
  */
  list.innerHTML = items.map((n) => `
    <div class="notif-item-wrap ${n.is_read == 0 ? 'unread' : 'read'}" data-id="${n.notification_id}">
      <a class="notif-item ${n.is_read == 0 ? 'unread' : 'read'}" href="${NOTIF_LINK}" onclick="markRead(${n.notification_id})">
        ${buildAvatarHtml(n.sender_name, n.sender_image)}
        <div class="notif-item-text">
          ${escapeHtml(n.message)}
          <span class="notif-item-time">${timeAgo(n.created_at)}</span>
        </div>
      </a>
      <div class="notif-item-right">
        <div class="notif-menu-wrap">
          <button class="notif-menu-btn" onclick="toggleDropdownMenu(this, event)">
            <span class="ndot"></span>
            <span class="ndot"></span>
            <span class="ndot"></span>
          </button>
          <div class="notif-menu-dropdown">
            <button class="notif-menu-item danger" onclick="deleteDropdownNotif(this)">
              <i class="fas fa-trash-alt"></i> Delete notification
            </button>
          </div>
        </div>
        <span class="notif-indicator ${n.is_read == 1 ? 'read' : ''}"></span>
      </div>
    </div>
  `).join("");
}

/* Toggle 3-dot dropdown in bell dropdown */
function toggleDropdownMenu(btn, e) {
  e.preventDefault();
  e.stopPropagation();
  const dropdown = btn.nextElementSibling;
  document.querySelectorAll('.notif-menu-dropdown.open').forEach(d => {
    if (d !== dropdown) d.classList.remove('open');
  });
  dropdown.classList.toggle('open');
}

/* Delete notification from bell dropdown */
function deleteDropdownNotif(btn) {
  const wrap = btn.closest('.notif-item-wrap');
  const id   = wrap.dataset.id;

  fetch('../../includes/delete_notification.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ notification_id: parseInt(id) })
  })
  .then(res => res.json())
  .then(data => {
    if (data.success) {
      wrap.style.transition = 'opacity 0.25s, transform 0.25s';
      wrap.style.opacity    = '0';
      wrap.style.transform  = 'translateX(16px)';
      setTimeout(() => {
        wrap.remove();
        fetchNotifications();
      }, 280);
    }
  })
  .catch(() => {});
}

/* Fetch notifications from role-based endpoint */
function fetchNotifications() {
  fetch(NOTIF_ENDPOINT)
    .then((res) => res.json())
    .then((data) => renderNotifications(data))
    .catch(() => {
      document.getElementById("notifList").innerHTML =
        '<div class="notif-empty">Could not load notifications.</div>';
    });
}

/* Toggle dropdown open/close on bell click */
function toggleNotifDropdown() {
  const btn      = document.getElementById("notifBtn");
  const dropdown = document.getElementById("notifDropdown");
  const isOpen   = dropdown.classList.contains("open");

  if (isOpen) {
    dropdown.classList.remove("open");
    btn.classList.remove("active");
  } else {
    dropdown.classList.add("open");
    btn.classList.add("active");
    fetchNotifications();
  }
}

/* Mark single or all notifications as read */
function markRead(id = null) {
  const body = id ? JSON.stringify({ id: id }) : JSON.stringify({});
  fetch(MARK_READ_ENDPOINT, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: body,
  })
    .then(() => {
      if (!id) {
        document.getElementById("notifCountBadge").style.display = "none";
        document.getElementById("notifUnreadBadge").style.display = "none";
      }
    })
    .catch(() => {});
}

/* Close dropdowns when clicking outside */
document.addEventListener("click", function (e) {
  const wrap = document.querySelector(".notif-wrap");
  if (wrap && !wrap.contains(e.target)) {
    document.getElementById("notifDropdown").classList.remove("open");
    document.getElementById("notifBtn").classList.remove("active");
  }
  document.querySelectorAll('.notif-menu-dropdown.open').forEach(d => d.classList.remove('open'));
});

/* Fetch on page load */
document.addEventListener("DOMContentLoaded", fetchNotifications);

/* Poll every 30 seconds */
setInterval(fetchNotifications, 30000);