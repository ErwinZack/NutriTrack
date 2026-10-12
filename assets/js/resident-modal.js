// resident-modal.js
// Controls the resident info modal popup — open, close, and populate data
function openResidentModal() {
    document.getElementById('residentModal').style.display = 'block';
}

function closeResidentModal() {
    document.getElementById('residentModal').style.display = 'none';
}

window.onclick = function (event) {
    const modal = document.getElementById('residentModal');
    if (event.target === modal) {
        modal.style.display = 'none';
    }
};
