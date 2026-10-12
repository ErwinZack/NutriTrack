// view_resident_records_pdf.js
// Generates a detailed PDF for viewing a specific resident's full health record
async function generateProfessionalPDF() {
  const { jsPDF } = window.jspdf;
  const doc = new jsPDF("landscape");

  const pageWidth = doc.internal.pageSize.getWidth();
  const pageHeight = doc.internal.pageSize.getHeight();
  const leftMargin = 14;
  const rightMargin = 14;

  // ── Pull resident info from DOM ────────────────────────
  const nameEl = document.querySelector(".resident-profile-name");
  const metaItems = document.querySelectorAll(".resident-profile-meta span");

  const residentName = nameEl ? nameEl.textContent.trim() : "Unknown Resident";

  const metaParts = [];
  metaItems.forEach((span) => {
    let text = "";
    span.childNodes.forEach((node) => {
      if (node.nodeType === Node.TEXT_NODE) text += node.textContent;
    });
    text = text.trim();
    if (text) metaParts.push(text);
  });
  const residentMeta = metaParts.join("   |   ");

  // ── Pull stats from stat strip ─────────────────────────
  const statCards = document.querySelectorAll(".resident-stat-card");
  const statData = [];
  statCards.forEach((card) => {
    const label =
      card.querySelector(".resident-stat-label")?.textContent.trim() || "";
    const value =
      card.querySelector(".resident-stat-value")?.textContent.trim() || "—";
    statData.push({ label, value });
  });

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
 doc.text("Barangay " + (window.CURRENT_BARANGAY_NAME || "Health Center") + ", Calasiao, Pangasinan", pageWidth / 2, 20, {
    align: "center",
});

  doc.setFont("helvetica", "bold");
  doc.setFontSize(12);
  doc.setTextColor(26, 31, 54);
  doc.text("NutriTrack Nutrition Monitoring System", pageWidth / 2, 27, {
    align: "center",
  });

  doc.setFont("helvetica", "normal");
  doc.setFontSize(11);
  doc.setTextColor(60, 80, 120);
  doc.text("Individual Health Records Report", pageWidth / 2, 33, {
    align: "center",
  });

  doc.setFontSize(9);
  doc.setTextColor(130);
  doc.text("Health records for: " + residentName, pageWidth / 2, 39, {
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

  // ── Resident info block ────────────────────────────────
  let currentY = 55;

  doc.setFillColor(235, 245, 255);
  doc.setDrawColor(37, 99, 235);
  doc.setLineWidth(0.3);
  doc.roundedRect(
    leftMargin,
    currentY,
    pageWidth - leftMargin - rightMargin,
    20,
    2,
    2,
    "FD",
  );

  doc.setFont("helvetica", "bold");
  doc.setFontSize(11);
  doc.setTextColor(26, 31, 54);
  doc.text("Resident: " + residentName, leftMargin + 4, currentY + 7);

  if (residentMeta) {
    doc.setFont("helvetica", "normal");
    doc.setFontSize(8.5);
    doc.setTextColor(80, 80, 80);
    doc.text(residentMeta, leftMargin + 4, currentY + 14);
  }

  doc.setTextColor(0);
  currentY += 26;

  // ── Stats strip ────────────────────────────────────────
  if (statData.length > 0) {
    const boxW =
      (pageWidth - leftMargin - rightMargin - (statData.length - 1) * 4) /
      statData.length;
    let boxX = leftMargin;
    const boxH = 14;

    statData.forEach((stat) => {
      doc.setFillColor(245, 250, 255);
      doc.setDrawColor(200, 220, 240);
      doc.setLineWidth(0.2);
      doc.roundedRect(boxX, currentY, boxW, boxH, 1.5, 1.5, "FD");

      doc.setFont("helvetica", "bold");
      doc.setFontSize(10);
      doc.setTextColor(26, 31, 54);
      doc.text(stat.value, boxX + boxW / 2, currentY + 6, { align: "center" });

      doc.setFont("helvetica", "normal");
      doc.setFontSize(7);
      doc.setTextColor(120, 120, 140);
      doc.text(stat.label.toUpperCase(), boxX + boxW / 2, currentY + 11, {
        align: "center",
      });

      boxX += boxW + 4;
    });

    doc.setTextColor(0);
    currentY += boxH + 6;
  }

  // ── Table ──────────────────────────────────────────────
  const table = document.querySelector(".table-default");
  if (!table) return alert("Table not found.");

  const tableClone = table.cloneNode(true);

  tableClone.querySelectorAll("thead tr").forEach((tr) => {
    const ths = tr.querySelectorAll("th");
    if (ths.length > 0) ths[0].remove();
    const thsAfter = tr.querySelectorAll("th");
    if (thsAfter.length > 0) thsAfter[thsAfter.length - 1].remove();
  });

  tableClone.querySelectorAll("tbody tr").forEach((tr) => {
    if (tr.style.display === "none") {
      tr.remove();
      return;
    }
    const tds = tr.querySelectorAll("td");
    if (tds.length > 0) tds[0].remove();
    const tdsAfter = tr.querySelectorAll("td");
    if (tdsAfter.length > 0) tdsAfter[tdsAfter.length - 1].remove();
    tdsAfter.forEach((td) => {
      const metric = td.querySelector(".metric");
      if (metric) {
        const dot = metric.querySelector(".metric-dot");
        if (dot) dot.remove();
        td.textContent = metric.textContent.trim();
      }
    });
  });

  doc.autoTable({
    html: tableClone,
    startY: currentY,
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
      halign: "center",
      lineColor: [80, 80, 80],
      lineWidth: 0.3,
    },
    bodyStyles: { halign: "center" },
    alternateRowStyles: { fillColor: [255, 255, 255] },
    rowStyles: { minCellHeight: 10 },
    didDrawPage: () => {
      const pw = doc.internal.pageSize.getWidth();
      const ph = doc.internal.pageSize.getHeight();
      const pageCount = doc.internal.getNumberOfPages();
      doc.setFontSize(8);
      doc.setTextColor(150);
      doc.text("Page " + pageCount, pw - rightMargin - 20, ph - 7);
      doc.text("NutriTrack © " + new Date().getFullYear(), leftMargin, ph - 7);
      if (pageCount > 1) {
        doc.setFontSize(8);
        doc.setTextColor(100);
        doc.text("Resident: " + residentName, pw / 2, ph - 7, {
          align: "center",
        });
      }
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
  doc.text(
    window.CURRENT_WORKER_NAME || "Health Worker",
    leftMargin,
    finalY + 13,
  );
  doc.setFont("helvetica", "normal");
  doc.setFontSize(9);
  doc.setTextColor(120);
  doc.text("Health Worker", leftMargin, finalY + 18);
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
