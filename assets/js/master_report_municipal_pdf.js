// master_report_municipal_pdf.js
// Generates a full municipal master summary PDF across all barangays
// Follows master_report_pdf.js pattern — scoped for municipal oversight only

async function generateMasterReportPDF() {
  // ── CRITICAL: Switch to Statistics tab so Chart.js renders all canvases ──
  const statTab = document.querySelector('.rpt-tab-btn[onclick*="statistics"]');
  if (
    statTab &&
    !document.getElementById("tab-statistics")?.classList.contains("active")
  ) {
    switchTab("statistics", statTab);
    await new Promise((resolve) => setTimeout(resolve, 700));
  }

  const { jsPDF } = window.jspdf;
  const doc = new jsPDF("landscape");

  const pageWidth = doc.internal.pageSize.getWidth();
  const pageHeight = doc.internal.pageSize.getHeight();
  const leftMargin = 14;
  const rightMargin = 14;

  const month =
    window.RPT_SELECTED_MONTH ||
    new Date().toLocaleDateString("en-PH", { month: "long", year: "numeric" });

  // ─────────────────────────────────────────────────────
  // SHARED HELPERS
  // ─────────────────────────────────────────────────────
  function _pdfHeader(reportTitle, reportSubtitle) {
    const systemLogo = document.getElementById("logoSystem");
    const dohLogo = document.getElementById("logoDOH");
    if (systemLogo) doc.addImage(systemLogo, "PNG", leftMargin, 8, 28, 28);
    if (dohLogo)
      doc.addImage(dohLogo, "PNG", pageWidth - rightMargin - 28, 8, 28, 28);

    doc.setFont("helvetica", "bold");
    doc.setFontSize(17);
    doc.setTextColor(26, 31, 54);
    doc.text("Municipality of Calasiao", pageWidth / 2, 14, {
      align: "center",
    });

    doc.setFont("helvetica", "normal");
    doc.setFontSize(10);
    doc.setTextColor(100);
    doc.text("Calasiao, Pangasinan", pageWidth / 2, 20, { align: "center" });

    doc.setFont("helvetica", "bold");
    doc.setFontSize(12);
    doc.setTextColor(26, 31, 54);
    doc.text("NutriTrack Nutrition Monitoring System", pageWidth / 2, 27, {
      align: "center",
    });

    doc.setFont("helvetica", "normal");
    doc.setFontSize(11);
    doc.setTextColor(60, 80, 120);
    doc.text(reportTitle, pageWidth / 2, 33, { align: "center" });

    if (reportSubtitle) {
      doc.setFontSize(9);
      doc.setTextColor(130);
      doc.text(reportSubtitle, pageWidth / 2, 39, { align: "center" });
    }

    doc.setDrawColor(37, 99, 235);
    doc.setLineWidth(0.7);
    const divY = reportSubtitle ? 43 : 38;
    doc.line(leftMargin, divY, pageWidth - rightMargin, divY);

    const formattedDate = new Date().toLocaleDateString("en-PH", {
      year: "numeric",
      month: "long",
      day: "numeric",
    });
    doc.setFontSize(8.5);
    doc.setTextColor(120);
    doc.text("Generated on: " + formattedDate, leftMargin, divY + 6);
    doc.setTextColor(0);

    return divY + 12;
  }

  function _pdfFooter() {
    doc.setFontSize(8);
    doc.setTextColor(150);
    doc.text(
      "Page " + doc.internal.getNumberOfPages(),
      pageWidth - rightMargin - 20,
      pageHeight - 7,
    );
    doc.text(
      "NutriTrack © " + new Date().getFullYear(),
      leftMargin,
      pageHeight - 7,
    );
    doc.setTextColor(0);
  }

  function _pdfSignature(startY) {
    let y = startY + 20;
    if (y + 30 > pageHeight) {
      doc.addPage();
      y = 40;
    }

    const rightX = pageWidth - rightMargin - 70;
    doc.setFontSize(9);
    doc.setTextColor(80);

    doc.text("Prepared by:", leftMargin, y);
    doc.text("__________________________", leftMargin, y + 8);
    doc.setFont("helvetica", "bold");
    doc.text(
      window.MUNICIPAL_ADMIN_NAME || "Municipal Admin",
      leftMargin,
      y + 13,
    );
    doc.setFont("helvetica", "normal");
    doc.setFontSize(9);
    doc.setTextColor(120);
    doc.text("Municipal Superadmin", leftMargin, y + 18);

    doc.setFontSize(9);
    doc.setTextColor(80);
    doc.text("Noted by:", rightX, y);
    doc.text("__________________________", rightX, y + 8);
    doc.text("Municipal Health Officer", rightX, y + 14);
    doc.setTextColor(0);
  }

  function _autoTableDefaults(startY) {
    return {
      startY,
      margin: { left: leftMargin, right: rightMargin },
      styles: {
        fontSize: 9,
        cellPadding: 3.5,
        lineColor: [180, 180, 180],
        lineWidth: 0.2,
        textColor: [30, 30, 30],
      },
      headStyles: {
        fillColor: [208, 208, 208],
        textColor: [20, 20, 20],
        fontStyle: "bold",
        fontSize: 9,
        lineColor: [120, 120, 120],
        lineWidth: 0.4,
      },
      alternateRowStyles: { fillColor: [245, 245, 245] },
      didDrawPage: () => _pdfFooter(),
    };
  }

  function _captureChart(
    canvasId,
    startY,
    chartW,
    chartH,
    fallbackTitle,
    fallbackSubtitle,
  ) {
    if (startY + chartH > pageHeight - 20) {
      doc.addPage();
      startY = _pdfHeader(fallbackTitle || "Chart", fallbackSubtitle || "");
    }
    const canvas = document.getElementById(canvasId);
    if (!canvas) return startY;
    try {
      const imgData = canvas.toDataURL("image/png");
      doc.addImage(
        imgData,
        "PNG",
        pageWidth / 2 - chartW / 2,
        startY,
        chartW,
        chartH,
      );
      return startY + chartH + 10;
    } catch (e) {
      console.warn("Chart capture failed:", canvasId, e);
      return startY;
    }
  }

  // ─────────────────────────────────────────────────────
  // PAGE 1 — COVER PAGE
  // ─────────────────────────────────────────────────────
  const systemLogo = document.getElementById("logoSystem");
  const dohLogo = document.getElementById("logoDOH");

  if (systemLogo)
    doc.addImage(systemLogo, "PNG", pageWidth / 2 - 22, 18, 44, 44);

  doc.setFont("helvetica", "bold");
  doc.setFontSize(22);
  doc.setTextColor(26, 31, 54);
  doc.text("Municipal Health Summary Report", pageWidth / 2, 75, {
    align: "center",
  });

  doc.setFont("helvetica", "normal");
  doc.setFontSize(13);
  doc.setTextColor(60, 80, 120);
  doc.text(month, pageWidth / 2, 85, { align: "center" });

  doc.setDrawColor(37, 99, 235);
  doc.setLineWidth(1);
  doc.line(leftMargin + 40, 91, pageWidth - rightMargin - 40, 91);

  doc.setFont("helvetica", "bold");
  doc.setFontSize(11);
  doc.setTextColor(26, 31, 54);
  doc.text("Municipality of Calasiao", pageWidth / 2, 102, { align: "center" });

  doc.setFont("helvetica", "normal");
  doc.setFontSize(10);
  doc.setTextColor(100);
  doc.text("Calasiao, Pangasinan", pageWidth / 2, 110, { align: "center" });
  doc.text("NutriTrack Nutrition Monitoring System", pageWidth / 2, 118, {
    align: "center",
  });

  doc.setFillColor(248, 250, 255);
  doc.setDrawColor(37, 99, 235);
  doc.setLineWidth(0.3);
  doc.roundedRect(
    leftMargin + 60,
    128,
    pageWidth - leftMargin - rightMargin - 120,
    50,
    3,
    3,
    "FD",
  );

  const formattedDate = new Date().toLocaleDateString("en-PH", {
    year: "numeric",
    month: "long",
    day: "numeric",
  });
  doc.setFont("helvetica", "normal");
  doc.setFontSize(9.5);
  doc.setTextColor(60, 80, 120);
  doc.text("Report Period:", pageWidth / 2, 140, { align: "center" });
  doc.setFont("helvetica", "bold");
  doc.setTextColor(26, 31, 54);
  doc.text(month, pageWidth / 2, 148, { align: "center" });
  doc.setFont("helvetica", "normal");
  doc.setTextColor(60, 80, 120);
  doc.text("Generated on:", pageWidth / 2, 158, { align: "center" });
  doc.setFont("helvetica", "bold");
  doc.setTextColor(26, 31, 54);
  doc.text(formattedDate, pageWidth / 2, 166, { align: "center" });

  if (dohLogo)
    doc.addImage(
      dohLogo,
      "PNG",
      pageWidth - rightMargin - 28,
      pageHeight - 40,
      24,
      24,
    );
  doc.setFontSize(8);
  doc.setTextColor(150);
  doc.text(
    "NutriTrack © " + new Date().getFullYear(),
    pageWidth / 2,
    pageHeight - 7,
    { align: "center" },
  );
  doc.setTextColor(0);

  // ─────────────────────────────────────────────────────
  // PAGE 2 — TOWN-WIDE MONTHLY NUTRITION REPORT
  // ─────────────────────────────────────────────────────
  doc.addPage();
  let startY = _pdfHeader(
    "Town-Wide Monthly Nutrition Report",
    "Aggregated BMI status across all barangays · " + month,
  );

  const stats = window.RPT_MONTHLY_STATS || {
    uw: 0,
    normal: 0,
    ow: 0,
    obese: 0,
    total: 0,
  };
  const total = stats.total || 1;
  const pct = (n) => (total > 0 ? ((n / total) * 100).toFixed(1) + "%" : "0%");

  const categories = [
    { label: "Underweight", color: [8, 145, 178], val: stats.uw },
    { label: "Normal", color: [22, 163, 74], val: stats.normal },
    { label: "Overweight", color: [217, 119, 6], val: stats.ow },
    { label: "Obese", color: [220, 38, 38], val: stats.obese },
  ];

  // KPI boxes
  const boxW = (pageWidth - leftMargin * 2 - 12) / 4;
  let bx = leftMargin;
  categories.forEach((cat) => {
    const p = stats.total > 0 ? Math.round((cat.val / stats.total) * 100) : 0;
    doc.setFillColor(...cat.color);
    doc.roundedRect(bx, startY, boxW, 22, 3, 3, "F");
    doc.setTextColor(255);
    doc.setFont("helvetica", "bold");
    doc.setFontSize(16);
    doc.text(String(cat.val), bx + boxW / 2, startY + 11, { align: "center" });
    doc.setFontSize(8);
    doc.setFont("helvetica", "normal");
    doc.text(cat.label + " (" + p + "%)", bx + boxW / 2, startY + 18, {
      align: "center",
    });
    bx += boxW + 4;
  });
  doc.setTextColor(0);
  startY += 30;

  // Horizontal bars
  const barMaxW = pageWidth - leftMargin * 2 - 40;
  categories.forEach((item, i) => {
    const p = stats.total > 0 ? item.val / stats.total : 0;
    const y = startY + i * 12;
    doc.setFontSize(8);
    doc.setTextColor(80);
    doc.text(item.label, leftMargin, y + 3.5);
    doc.setFillColor(230, 235, 245);
    doc.roundedRect(leftMargin + 38, y, barMaxW, 6, 1, 1, "F");
    if (p > 0) {
      doc.setFillColor(...item.color);
      doc.roundedRect(leftMargin + 38, y, barMaxW * p, 6, 1, 1, "F");
    }
    doc.setTextColor(...item.color);
    doc.text(
      item.val + " (" + Math.round(p * 100) + "%)",
      pageWidth - leftMargin - 2,
      y + 3.5,
      { align: "right" },
    );
  });
  doc.setTextColor(0);
  startY += categories.length * 12 + 8;

  doc.autoTable({
    head: [["BMI Status", "Count", "Percentage"]],
    body: [
      ["Underweight", stats.uw, pct(stats.uw)],
      ["Normal", stats.normal, pct(stats.normal)],
      ["Overweight", stats.ow, pct(stats.ow)],
      ["Obese", stats.obese, pct(stats.obese)],
    ],
    ..._autoTableDefaults(startY),
    columnStyles: {
      0: { fontStyle: "bold" },
      2: { halign: "center" },
      3: { halign: "center" },
    },
  });
  _pdfSignature(doc.lastAutoTable.finalY);

  // ─────────────────────────────────────────────────────
  // PAGE 3 — PER-BARANGAY COMPARISON
  // ─────────────────────────────────────────────────────
  doc.addPage();
  startY = _pdfHeader(
    "Per-Barangay Comparison Report",
    "Residents, records, and at-risk % ranked by risk level",
  );

  startY = _captureChart(
    "barangayCompareChart",
    startY,
    200,
    65,
    "Per-Barangay Comparison Report",
    "Residents, records, and at-risk % ranked by risk level",
  );

  doc.autoTable({
    head: [
      [
        "Barangay",
        "Residents",
        "Records",
        "Assessed",
        "At-Risk",
        "At-Risk %",
        "Active BHWs",
      ],
    ],
    body: (window.RPT_BARANGAY_DATA || []).map((b) => [
      b.barangay_name,
      b.residents,
      b.records,
      b.assessed,
      b.at_risk,
      b.at_risk_pct + "%",
      b.active_bhws,
    ]),
    ..._autoTableDefaults(startY),
    columnStyles: {
      0: { fontStyle: "bold" },
      1: { halign: "center" },
      2: { halign: "center" },
      3: { halign: "center" },
      4: { halign: "center" },
      5: { halign: "center" },
      6: { halign: "center" },
    },
  });
  _pdfSignature(doc.lastAutoTable.finalY);

  // ─────────────────────────────────────────────────────
  // PAGE 4 — GENDER-BASED NUTRITION COMPARISON
  // ─────────────────────────────────────────────────────
  doc.addPage();
  startY = _pdfHeader(
    "Gender-Based Nutrition Comparison",
    "Town-wide BMI split by gender across all residents",
  );

  startY = _captureChart(
    "genderBmiChart",
    startY,
    180,
    75,
    "Gender-Based Nutrition Comparison",
    "Town-wide BMI split by gender across all residents",
  );

  const gs = window.RPT_GENDER_STATS || {};
  doc.autoTable({
    head: [
      [
        "Gender",
        "Total",
        "Avg BMI",
        "Underweight",
        "Normal",
        "Overweight",
        "Obese",
        "At-Risk",
      ],
    ],
    body: Object.entries(gs).map(([g, s]) => [
      g,
      s.total,
      s.avg_bmi,
      s.uw,
      s.normal,
      s.ow,
      s.obese,
      s.at_risk,
    ]),
    ..._autoTableDefaults(startY),
    columnStyles: {
      0: { fontStyle: "bold" },
      1: { halign: "center" },
      2: { halign: "center" },
      3: { halign: "center" },
      4: { halign: "center" },
      5: { halign: "center" },
      6: { halign: "center" },
      7: { halign: "center" },
    },
  });
  _pdfSignature(doc.lastAutoTable.finalY);

  // ─────────────────────────────────────────────────────
  // PAGE 5 — NEW RESIDENTS REGISTERED
  // ─────────────────────────────────────────────────────
  doc.addPage();
  startY = _pdfHeader(
    "New Residents Registered",
    "Monthly enrollment count — last 6 months, town-wide",
  );

  startY = _captureChart(
    "newResidentsChart",
    startY,
    200,
    75,
    "New Residents Registered",
    "Monthly enrollment count — last 6 months, town-wide",
  );

  const newResData = window.RPT_NEW_RESIDENTS_DATA || [];
  const totalNew = newResData.reduce((s, r) => s + r.count, 0);

  doc.autoTable({
    head: [["Month", "New Registrations"]],
    body:
      newResData.length > 0
        ? newResData.map((r) => [r.label, r.count])
        : [["No data available", "—"]],
    ..._autoTableDefaults(startY),
    columnStyles: {
      0: { fontStyle: "bold" },
      1: { halign: "center" },
    },
  });
  const newFinalY = doc.lastAutoTable.finalY;
  doc.setFontSize(9);
  doc.setFont("helvetica", "bold");
  doc.setTextColor(26, 31, 54);
  doc.text(
    "Total New Registrations (6 months): " + totalNew,
    leftMargin,
    newFinalY + 8,
  );
  doc.setFont("helvetica", "normal");
  doc.setTextColor(0);
  _pdfSignature(newFinalY + 8);

  // ─────────────────────────────────────────────────────
  // PAGE 6 — MONTHLY AT-RISK TREND
  // ─────────────────────────────────────────────────────
  doc.addPage();
  startY = _pdfHeader(
    "Monthly At-Risk Trend",
    "Month-over-month at-risk resident count — town-wide, last 6 months",
  );

  startY = _captureChart(
    "atRiskTrendChart",
    startY,
    200,
    80,
    "Monthly At-Risk Trend",
    "Month-over-month at-risk resident count — town-wide, last 6 months",
  );

  doc.autoTable({
    head: [["Month", "At-Risk Residents"]],
    body:
      (window.RPT_AT_RISK_DATA || []).length > 0
        ? (window.RPT_AT_RISK_DATA || []).map((r) => [r.label, r.count])
        : [["No data available", "—"]],
    ..._autoTableDefaults(startY),
    columnStyles: {
      0: { fontStyle: "bold" },
      1: { halign: "center" },
    },
  });
  _pdfSignature(doc.lastAutoTable.finalY);

  // ─────────────────────────────────────────────────────
  // PAGE 7 — BHW WORKFORCE COVERAGE
  // ─────────────────────────────────────────────────────
  doc.addPage();
  startY = _pdfHeader(
    "BHW Workforce Coverage Report",
    "Active BHWs vs resident count per barangay — staffing ratio",
  );

  startY = _captureChart(
    "bhwCoverageChart",
    startY,
    200,
    75,
    "BHW Workforce Coverage Report",
    "Active BHWs vs resident count per barangay — staffing ratio",
  );

  doc.autoTable({
    head: [["Barangay", "Residents", "Active BHWs"]],
    body: (window.RPT_BARANGAY_DATA || []).map((b) => [
      b.barangay_name,
      b.residents,
      b.active_bhws,
    ]),
    ..._autoTableDefaults(startY),
    columnStyles: {
      0: { fontStyle: "bold" },
      1: { halign: "center" },
      2: { halign: "center" },
    },
  });
  _pdfSignature(doc.lastAutoTable.finalY);

  // ─────────────────────────────────────────────────────
  // PAGE 8 — CERTIFICATION & SIGNATURES
  // ─────────────────────────────────────────────────────
  doc.addPage();
  startY = _pdfHeader(
    "Certification & Signatures",
    "Municipal Health Summary Report — " + month,
  );

  doc.setFillColor(248, 250, 255);
  doc.setDrawColor(37, 99, 235);
  doc.setLineWidth(0.3);
  doc.roundedRect(
    leftMargin,
    startY,
    pageWidth - leftMargin - rightMargin,
    30,
    3,
    3,
    "FD",
  );

  doc.setFont("helvetica", "bold");
  doc.setFontSize(10);
  doc.setTextColor(26, 31, 54);
  doc.text("CERTIFICATION", pageWidth / 2, startY + 8, { align: "center" });

  doc.setFont("helvetica", "normal");
  doc.setFontSize(8.5);
  doc.setTextColor(80);
  const certText =
    "This is to certify that the health records and nutrition data contained in this report are true and accurate " +
    "as recorded and compiled by the Municipal Health Office of Calasiao, Pangasinan for the period of " +
    month +
    ". " +
    "Data is aggregated from all active barangays under the NutriTrack Nutrition Monitoring System.";
  const certLines = doc.splitTextToSize(
    certText,
    pageWidth - leftMargin - rightMargin - 16,
  );
  doc.text(certLines, pageWidth / 2, startY + 16, { align: "center" });
  doc.setTextColor(0);
  startY += 38;

  const sigW = (pageWidth - leftMargin - rightMargin - 20) / 3;
  [
    {
      title: "Prepared by:",
      name: window.MUNICIPAL_ADMIN_NAME || "Municipal Admin",
      role: "Municipal Superadmin",
    },
    { title: "Reviewed by:", name: "", role: "Municipal Health Officer" },
    { title: "Approved by:", name: "", role: "Municipal Mayor" },
  ].forEach((sig, i) => {
    const sx = leftMargin + i * (sigW + 10);
    doc.setFont("helvetica", "normal");
    doc.setFontSize(9);
    doc.setTextColor(80);
    doc.text(sig.title, sx, startY);
    doc.setDrawColor(26, 31, 54);
    doc.setLineWidth(0.4);
    doc.line(sx, startY + 18, sx + sigW, startY + 18);
    if (sig.name) {
      doc.setFont("helvetica", "bold");
      doc.setFontSize(8.5);
      doc.setTextColor(26, 31, 54);
      doc.text(sig.name, sx + sigW / 2, startY + 22, { align: "center" });
    }
    doc.setFont("helvetica", "bold");
    doc.setFontSize(8.5);
    doc.setTextColor(26, 31, 54);
    doc.text(sig.role, sx + sigW / 2, startY + (sig.name ? 27 : 24), {
      align: "center",
    });
    doc.setFont("helvetica", "normal");
    doc.setFontSize(8);
    doc.setTextColor(120);
    doc.text("Signature over printed name", sx + sigW / 2, startY + 33, {
      align: "center",
    });
    doc.text("Date: ____________________", sx + sigW / 2, startY + 40, {
      align: "center",
    });
  });
  doc.setTextColor(0);
  startY += 52;

  doc.setFont("helvetica", "bold");
  doc.setFontSize(9);
  doc.setTextColor(100);
  doc.text("OFFICIAL SEAL:", leftMargin, startY);
  doc.setDrawColor(180);
  doc.setLineWidth(0.2);
  doc.circle(leftMargin + 25, startY + 18, 18, "S");
  doc.setFont("helvetica", "normal");
  doc.setFontSize(7);
  doc.text("(Place official seal here)", leftMargin + 25, startY + 40, {
    align: "center",
  });

  doc.setFont("helvetica", "italic");
  doc.setFontSize(7.5);
  doc.setTextColor(150);
  doc.text(
    "This document is computer-generated by NutriTrack Health Monitoring System. " +
      "For verification, contact the Municipal Health Office of Calasiao, Pangasinan.",
    pageWidth / 2,
    pageHeight - 14,
    { align: "center" },
  );

  _pdfFooter();

  // ─────────────────────────────────────────────────────
  // OPEN AND PRINT
  // ─────────────────────────────────────────────────────
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
