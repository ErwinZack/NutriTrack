// user_management_pdf.js
// Generates a PDF of system users, roles, and access levels

async function generateUsersPDF() {
  const { jsPDF } = window.jspdf;
  const doc = new jsPDF("landscape");

  const pageWidth = doc.internal.pageSize.getWidth();
  const pageHeight = doc.internal.pageSize.getHeight();
  const leftMargin = 14;
  const rightMargin = 14;

  // ── Branded header ─────────────────────────────────────
  const systemLogo = document.getElementById("logoSystem");
  const dohLogo = document.getElementById("logoDOH");
  if (systemLogo) doc.addImage(systemLogo, "PNG", leftMargin, 8, 28, 28);
  if (dohLogo)
    doc.addImage(dohLogo, "PNG", pageWidth - rightMargin - 28, 8, 28, 28);

  doc.setFont("helvetica", "bold");
  doc.setFontSize(17);
  doc.setTextColor(26, 31, 54);
  doc.text("Barangay Health Center", pageWidth / 2, 14, { align: "center" });

  doc.setFont("helvetica", "normal");
  doc.setFontSize(10);
  doc.setTextColor(100);
  doc.text(
    "Barangay " +
      (window.CURRENT_BARANGAY_NAME || "Health Center") +
      ", Calasiao, Pangasinan",
    pageWidth / 2,
    20,
    { align: "center" },
  );

  doc.setFont("helvetica", "bold");
  doc.setFontSize(12);
  doc.setTextColor(26, 31, 54);
  doc.text("NutriTrack Nutrition Monitoring System", pageWidth / 2, 27, {
    align: "center",
  });

  doc.setFont("helvetica", "normal");
  doc.setFontSize(11);
  doc.setTextColor(60, 80, 120);
  doc.text("Health Worker Accounts Report", pageWidth / 2, 33, {
    align: "center",
  });

  doc.setFontSize(9);
  doc.setTextColor(130);
  doc.text("List of all registered health worker accounts", pageWidth / 2, 39, {
    align: "center",
  });

  // Divider
  doc.setDrawColor(37, 99, 235);
  doc.setLineWidth(0.7);
  doc.line(leftMargin, 43, pageWidth - rightMargin, 43);

  // Generated on
  const formattedDate = new Date().toLocaleDateString("en-PH", {
    year: "numeric",
    month: "long",
    day: "numeric",
  });
  doc.setFontSize(8.5);
  doc.setTextColor(120);
  doc.text("Generated on: " + formattedDate, leftMargin, 49);
  doc.setTextColor(0);

  const startY = 55;

  // ── Build body manually (mirrors audit_logs_pdf.js pattern) ──
  const headCells = ["#", "Full Name", "Username", "Email", "Status", "Created At"];
  const bodyRows = [];
  let counter = 1;

  document.querySelectorAll("#workersTableBody tr").forEach((tr) => {
    if (tr.style.display === "none") return;
    const tds = tr.querySelectorAll("td");
    bodyRows.push([
      counter++,
      tds[1]?.textContent?.trim() || "",
      tds[2]?.textContent?.trim() || "",
      tds[3]?.textContent?.trim() || "",
      tds[4]?.textContent?.trim() || "",
      tds[5]?.textContent?.trim() || "",
    ]);
  });

  doc.autoTable({
    head: [headCells],
    body: bodyRows,
    startY,
    margin: { left: leftMargin, right: rightMargin },
    styles: {
      fontSize: 8.5,
      cellPadding: 3.5,
      lineColor: [80, 80, 80],
      lineWidth: 0.1,
      textColor: [30, 30, 30],
    },
    headStyles: {
      fillColor: [208, 208, 208],
      textColor: [0, 0, 0],
      fontStyle: "bold",
      fontSize: 9,
      lineColor: [80, 80, 80],
      lineWidth: 0.3,
    },
    alternateRowStyles: { fillColor: [255, 255, 255] },
    rowStyles: { minCellHeight: 10 },
    columnStyles: {
      0: { halign: "center", cellWidth: 12 },
      1: { cellWidth: 55 },
      2: { cellWidth: 40 },
      3: { cellWidth: "auto" },
      4: { halign: "center", cellWidth: 25 },
      5: { cellWidth: 45 },
    },
    didDrawPage: () => {
      const pw = doc.internal.pageSize.getWidth();
      const ph = doc.internal.pageSize.getHeight();
      doc.setFontSize(8);
      doc.setTextColor(150);
      doc.text(
        "Page " + doc.internal.getNumberOfPages(),
        pw - rightMargin - 20,
        ph - 7,
      );
      doc.text("NutriTrack © " + new Date().getFullYear(), leftMargin, ph - 7);
      doc.setTextColor(0);
    },
  });

  // ── Signature block ────────────────────────────────────
  let finalY = doc.lastAutoTable.finalY + 20;
  if (finalY + 30 > pageHeight) {
    doc.addPage();
    finalY = 40;
  }

  doc.setFontSize(9);
  doc.setTextColor(80);
  const rightX = pageWidth - rightMargin - 70;

  doc.text("Prepared by:", leftMargin, finalY);
  doc.text("__________________________", leftMargin, finalY + 8);
  doc.setFont("helvetica", "bold");
  doc.text(window.CURRENT_WORKER_NAME || "Admin", leftMargin, finalY + 13);
  doc.setFont("helvetica", "normal");
  doc.setFontSize(9);
  doc.setTextColor(120);
  doc.text("Barangay Admin", leftMargin, finalY + 18);
  doc.setFontSize(9);
  doc.setTextColor(80);

doc.text("Noted by:", rightX, finalY);
doc.text("__________________________", rightX, finalY + 8);
doc.text("Barangay Health Officer", rightX, finalY + 14);

doc.setTextColor(0);


  // ── Open and print ─────────────────────────────────────
  const pdfBlob = doc.output("blob");
  const pdfUrl = URL.createObjectURL(pdfBlob);
  const win = window.open(pdfUrl);
  if (win) {
    win.onload = () => {
      win.focus();
      win.print();
    };
  }
}
