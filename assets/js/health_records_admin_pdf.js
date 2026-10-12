// health_records_admin_pdf.js
// Generates PDF of health records from the admin's perspective
async function generateHealthRecordsAdminPDF() {
  const { jsPDF } = window.jspdf;
  const doc = new jsPDF("landscape");

  const pageWidth = doc.internal.pageSize.getWidth();
  const pageHeight = doc.internal.pageSize.getHeight();
  const leftMargin = 14;
  const rightMargin = 14;

  // ── Header ──
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
  doc.text("Barangay Calasiao, San Miguel, Pangasinan", pageWidth / 2, 20, {
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
  doc.text("Health Records Overview", pageWidth / 2, 33, { align: "center" });

  doc.setFontSize(9);
  doc.setTextColor(130);
  doc.text(
    "All health records across all workers · system-wide view",
    pageWidth / 2,
    39,
    { align: "center" },
  );

  doc.setDrawColor(37, 99, 235);
  doc.setLineWidth(0.7);
  doc.line(leftMargin, 43, pageWidth - rightMargin, 43);

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

  // ── Manual body from visible rows ──
  const headCells = [
    "#",
    "Resident",
    "Gender / Age",
    "Date",
    "Weight",
    "Height",
    "BMI",
    "Status",
    "Recorded By",
  ];
  const bodyRows = [];
  let counter = 1;

  document.querySelectorAll("#hrTableBody tr").forEach((tr) => {
    if (tr.style.display === "none") return;
    const tds = tr.querySelectorAll("td");

    // Resident name + address
    const resCell = tds[1]?.querySelector(".admin-table-worker-cell");
    const resName =
      resCell?.querySelector(".dash-cell-name")?.textContent?.trim() || "";
    const resAddr =
      resCell?.querySelector(".dash-cell-sub")?.textContent?.trim() || "";

    // BMI value
    const bmiVal =
      tds[6]?.querySelector(".admin-vw-bmi-badge")?.textContent?.trim() || "—";

    // Status
    const statusBadge = tds[7]?.querySelector(".admin-hr-status-badge");
    const status = statusBadge?.textContent?.trim() || "—";

    // Recorded by
    const workerCell = tds[8]?.querySelector(".admin-table-worker-cell");
    const workerName =
      workerCell?.querySelector(".dash-cell-name")?.textContent?.trim() || "";
    const workerDept =
      workerCell?.querySelector(".dash-cell-sub")?.textContent?.trim() || "";

    bodyRows.push([
      counter++,
      resName + (resAddr ? "\n" + resAddr : ""),
      tds[2]?.textContent?.trim() || "",
      tds[3]?.textContent?.trim() || "",
      tds[4]?.textContent?.trim() || "",
      tds[5]?.textContent?.trim() || "",
      bmiVal,
      status,
      workerName + (workerDept ? "\n" + workerDept : ""),
    ]);
  });

  doc.autoTable({
    head: [headCells],
    body: bodyRows,
    startY,
    margin: { left: leftMargin, right: rightMargin },
    styles: {
      fontSize: 8.5,
      cellPadding: 3,
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
    columnStyles: {
      0: { halign: "center", cellWidth: 10 },
      1: { cellWidth: 55 },
      2: { cellWidth: 28 },
      3: { cellWidth: 28 },
      4: { halign: "center", cellWidth: 20 },
      5: { halign: "center", cellWidth: 20 },
      6: { halign: "center", cellWidth: 18 },
      7: { cellWidth: 28 },
      8: { cellWidth: 50 },
    },
    didParseCell: (data) => {
      if (data.section === "body" && data.column.index === 6) {
        const val = parseFloat(data.cell.raw);
        if (val < 18.5) data.cell.styles.textColor = [8, 145, 178];
        else if (val < 25.0) data.cell.styles.textColor = [22, 163, 74];
        else if (val < 30.0) data.cell.styles.textColor = [217, 119, 6];
        else if (val >= 30) data.cell.styles.textColor = [220, 38, 38];
        data.cell.styles.fontStyle = "bold";
      }
      if (data.section === "body" && data.column.index === 7) {
        const statusColorMap = {
          Normal: [22, 163, 74],
          Underweight: [8, 145, 178],
          Overweight: [217, 119, 6],
          Obese: [220, 38, 38],
        };
        const color = statusColorMap[data.cell.raw?.toString().trim()];
        if (color) data.cell.styles.textColor = color;
      }
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

  // ── Signature ──
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

  // ── Open and print ──
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
