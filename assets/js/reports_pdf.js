// reports_pdf.js
// Generates general statistical and analytics PDF reports
function _pdfHeader(doc, reportTitle, reportSubtitle) {
  const pageWidth = doc.internal.pageSize.getWidth();
  const leftMargin = 14;
  const rightMargin = 14;

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
  doc.text(reportTitle, pageWidth / 2, 33, { align: "center" });

  if (reportSubtitle) {
    doc.setFontSize(9);
    doc.setTextColor(130);
    doc.text(reportSubtitle, pageWidth / 2, 39, { align: "center" });
  }

  // Divider line
  doc.setDrawColor(37, 99, 235);
  doc.setLineWidth(0.7);
  const divY = reportSubtitle ? 43 : 38;
  doc.line(leftMargin, divY, pageWidth - rightMargin, divY);

  // Generated on / health worker meta
  const formattedDate = new Date().toLocaleDateString("en-PH", {
    year: "numeric",
    month: "long",
    day: "numeric",
  });
  doc.setFontSize(8.5);
  doc.setTextColor(120);
  doc.text("Generated on: " + formattedDate, leftMargin, divY + 6);
  doc.setTextColor(0);

  return divY + 12; // startY for content
}

// ─────────────────────────────────────────────────────────────
// SHARED SIGNATURE BLOCK
// ─────────────────────────────────────────────────────────────
function _pdfSignature(doc, startY) {
  const pageWidth = doc.internal.pageSize.getWidth();
  const pageHeight = doc.internal.pageSize.getHeight();
  const leftMargin = 14;
  const rightMargin = 14;

  let y = startY + 20;
  if (y + 30 > pageHeight) {
    doc.addPage();
    y = 40;
  }

  doc.setFontSize(9);
  doc.setTextColor(80);
  const rightX = pageWidth - rightMargin - 70;

  doc.text("Prepared by:", leftMargin, y);
  doc.text("__________________________", leftMargin, y + 8);
  doc.setFont("helvetica", "bold");
  doc.text(window.CURRENT_WORKER_NAME || "Health Worker", leftMargin, y + 13);
  doc.setFont("helvetica", "normal");
  doc.setFontSize(9);
  doc.setTextColor(120);
  doc.text("Health Worker", leftMargin, y + 18);
  doc.setFontSize(9);
  doc.setTextColor(80);

  doc.text("Noted by:", rightX, y);
  doc.text("__________________________", rightX, y + 8);
  doc.text("Barangay Health Officer", rightX, y + 14);

  doc.setTextColor(0);
}

// ─────────────────────────────────────────────────────────────
// SHARED PAGE FOOTER (page numbers)
// Used inside didDrawPage callbacks
// ─────────────────────────────────────────────────────────────
function _pdfFooter(doc) {
  const pageWidth = doc.internal.pageSize.getWidth();
  const pageHeight = doc.internal.pageSize.getHeight();
  const rightMargin = 14;
  doc.setFontSize(8);
  doc.setTextColor(150);
  doc.text(
    "Page " + doc.internal.getNumberOfPages(),
    pageWidth - rightMargin - 20,
    pageHeight - 7,
  );
  doc.text("NutriTrack © " + new Date().getFullYear(), 14, pageHeight - 7);
  doc.setTextColor(0);
}

// ─────────────────────────────────────────────────────────────
// SHARED: open PDF blob in new window and trigger print
// ─────────────────────────────────────────────────────────────
function _openAndPrint(doc) {
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

// ─────────────────────────────────────────────────────────────
// SHARED: autoTable defaults
// ─────────────────────────────────────────────────────────────
function _autoTableDefaults(doc, startY) {
  return {
    startY,
    margin: { left: 14, right: 14 },
    styles: {
      fontSize: 9,
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
    didDrawPage: () => _pdfFooter(doc),
  };
}

// ============================================================
// 1. MONTHLY NUTRITION REPORT
// ============================================================
async function generateMonthlyNutritionPDF() {
  const { jsPDF } = window.jspdf;
  const doc = new jsPDF("landscape");

  const pageWidth = doc.internal.pageSize.getWidth();
  const leftMargin = 14;

  // Read month label from the card meta
  const monthLabel =
    document
      .querySelector(
        '#statCardsGrid .rpt-card[data-title="monthly nutrition report"] .rpt-card-desc',
      )
      ?.textContent?.match(/for (.+)\./)?.[1] ||
    new Date().toLocaleDateString("en-PH", { month: "long", year: "numeric" });

  let startY = _pdfHeader(
    doc,
    "Monthly Nutrition Report",
    "Summary for " + monthLabel,
  );

  // ── Summary KPI boxes ──────────────────────────────────
  const categories = [
    { label: "Underweight", color: [8, 145, 178], key: "uw" },
    { label: "Normal", color: [22, 163, 74], key: "nm" },
    { label: "Overweight", color: [217, 119, 6], key: "ow" },
    { label: "Obese", color: [220, 38, 38], key: "ob" },
  ];

  // Pull counts from the rendered progress bar rows
  const barRows = document.querySelectorAll(
    '#statCardsGrid .rpt-card[data-title="monthly nutrition report"] .rpt-stat-bar-row',
  );
  const counts = {};
  let total = 0;
  barRows.forEach((row, i) => {
    const text = row.querySelector(".rpt-stat-bar-meta")?.textContent || "";
    const match = text.match(/(\d+)\s*\((\d+)%\)/);
    const count = match ? parseInt(match[1]) : 0;
    counts[categories[i]?.key] = count;
    total += count;
  });

  const boxW = (pageWidth - leftMargin * 2 - 12) / 4;
  let bx = leftMargin;
  categories.forEach((cat, i) => {
    const cnt = counts[cat.key] || 0;
    const pct = total > 0 ? Math.round((cnt / total) * 100) : 0;
    doc.setFillColor(...cat.color);
    doc.roundedRect(bx, startY, boxW, 22, 3, 3, "F");
    doc.setTextColor(255);
    doc.setFont("helvetica", "bold");
    doc.setFontSize(16);
    doc.text(String(cnt), bx + boxW / 2, startY + 11, { align: "center" });
    doc.setFontSize(8);
    doc.setFont("helvetica", "normal");
    doc.text(cat.label + " (" + pct + "%)", bx + boxW / 2, startY + 18, {
      align: "center",
    });
    bx += boxW + 4;
  });
  doc.setTextColor(0);
  startY += 30;

  // ── Native horizontal bar chart ────────────────────────
  const barData = [
    { label: "Underweight", color: [8, 145, 178], key: "uw" },
    { label: "Normal", color: [22, 163, 74], key: "nm" },
    { label: "Overweight", color: [217, 119, 6], key: "ow" },
    { label: "Obese", color: [220, 38, 38], key: "ob" },
  ];
  const barMaxW = pageWidth - leftMargin * 2 - 40;
  barData.forEach((item, i) => {
    const cnt = counts[item.key] || 0;
    const pct = total > 0 ? cnt / total : 0;
    const y = startY + i * 12;
    doc.setFontSize(8);
    doc.setTextColor(80);
    doc.text(item.label, leftMargin, y + 3.5);
    doc.setFillColor(230, 235, 245);
    doc.roundedRect(leftMargin + 38, y, barMaxW, 6, 1, 1, "F");
    doc.setFillColor(...item.color);
    doc.roundedRect(leftMargin + 38, y, barMaxW * pct, 6, 1, 1, "F");
    doc.setTextColor(...item.color);
    doc.text(
      cnt + " (" + Math.round(pct * 100) + "%)",
      pageWidth - leftMargin - 2,
      y + 3.5,
      { align: "right" },
    );
  });
  doc.setTextColor(0);
  startY += barData.length * 12 + 8;

  // ── Status breakdown table ─────────────────────────────
  const tableBody = categories.map((cat) => {
    const cnt = counts[cat.key] || 0;
    const pct = total > 0 ? Math.round((cnt / total) * 100) : 0;
    return [cat.label, cnt, pct + "%"];
  });

  doc.autoTable({
    head: [["BMI Status", "Count", "Percentage"]],
    body: tableBody,
    ..._autoTableDefaults(doc, startY),
    columnStyles: {
      0: { fontStyle: "bold" },
      1: { halign: "center" },
      2: { halign: "center" },
    },
    bodyStyles: { textColor: [26, 31, 54] },
    didParseCell: (data) => {
      if (data.section === "body" && data.column.index === 0) {
        data.cell.styles.fontStyle = "bold";
      }
    },
    didDrawPage: () => _pdfFooter(doc),
  });

  _pdfSignature(doc, doc.lastAutoTable.finalY);
  _openAndPrint(doc);
}

// ============================================================
// 2. BMI DISTRIBUTION (ALL-TIME)
// ============================================================
async function generateBmiDistributionPDF() {
  const { jsPDF } = window.jspdf;
  const doc = new jsPDF("landscape");

  const pageWidth = doc.internal.pageSize.getWidth();

  let startY = _pdfHeader(
    doc,
    "BMI Distribution Report (All-time)",
    "Based on latest health record per resident",
  );

  // ── Capture doughnut chart ─────────────────────────────
  const canvas = document.getElementById("statBmiChart");
  if (canvas) {
    try {
      const imgData = canvas.toDataURL("image/png");
      const chartW = 120;
      const chartH = 70;
      doc.addImage(
        imgData,
        "PNG",
        pageWidth / 2 - chartW / 2,
        startY,
        chartW,
        chartH,
      );
      startY += chartH + 10;
    } catch (e) {}
  }

  // ── Pull counts from always-visible stat bar rows ─────
  const distMeta = [
    { label: "Underweight", range: "BMI < 18.5" },
    { label: "Normal", range: "18.5 - 24.9" },
    { label: "Overweight", range: "25.0 - 29.9" },
    { label: "Obese", range: "BMI >= 30" },
  ];
  const rows = [];
  document.querySelectorAll(".rpt-stat-bar-row").forEach((row, i) => {
    if (i >= 4) return;
    const text =
      row
        .querySelector(".rpt-stat-bar-meta span:last-child")
        ?.textContent?.trim() || "";
    const match = text.match(/^(\d+)/);
    const count = match ? parseInt(match[1]) : 0;
    rows.push([distMeta[i].label, distMeta[i].range, count]);
  });

  const totalCount = rows.reduce((s, r) => s + r[2], 0);
  const tableBody = rows.map((r) => [
    r[0],
    r[1],
    r[2],
    totalCount > 0 ? Math.round((r[2] / totalCount) * 100) + "%" : "0%",
  ]);

  doc.autoTable({
    head: [["BMI Status", "BMI Range", "Resident Count", "Percentage"]],
    body: tableBody,
    ..._autoTableDefaults(doc, startY),
    columnStyles: {
      0: { fontStyle: "bold" },
      2: { halign: "center" },
      3: { halign: "center" },
    },
    didParseCell: (data) => {
      if (data.section === "body" && data.column.index === 0) {
        data.cell.styles.fontStyle = "bold";
      }
    },
    didDrawPage: () => _pdfFooter(doc),
  });

  _pdfSignature(doc, doc.lastAutoTable.finalY);
  _openAndPrint(doc);
}

// ============================================================
// 3. AGE GROUP ANALYSIS
// ============================================================
async function generateAgeGroupPDF() {
  const { jsPDF } = window.jspdf;
  const doc = new jsPDF("landscape");

  let startY = _pdfHeader(
    doc,
    "Age Group Analysis Report",
    "Resident count by age group — based on latest health records",
  );

  // Read from the always-visible .rpt-age-bar-row elements
  const rows = [];
  document.querySelectorAll(".rpt-age-bar-row").forEach((row) => {
    const label =
      row.querySelector(".rpt-age-bar-label")?.textContent?.trim() || "";
    const count =
      row.querySelector(".rpt-age-bar-count")?.textContent?.trim() || "0";
    if (label) rows.push([label, parseInt(count) || 0]);
  });

  if (rows.length === 0) {
    doc.setFontSize(9);
    doc.setTextColor(150);
    doc.text("No age group data available.", 14, startY + 6);
    doc.setTextColor(0);
    _openAndPrint(doc);
    return;
  }

  const total = rows.reduce((s, r) => s + r[1], 0);
  const tableBody = rows.map((r) => [
    r[0],
    r[1],
    total > 0 ? Math.round((r[1] / total) * 100) + "%" : "0%",
  ]);

  doc.autoTable({
    head: [["Age Group", "Resident Count", "Percentage"]],
    body: tableBody,
    ..._autoTableDefaults(doc, startY),
    columnStyles: {
      0: { fontStyle: "bold" },
      1: { halign: "center" },
      2: { halign: "center" },
    },
    didParseCell: (data) => {
      if (data.section === "body" && data.column.index === 1) {
        const val = parseInt(data.cell.raw);
        if (val > 0) data.cell.styles.textColor = [37, 99, 235];
      }
    },
    didDrawPage: () => _pdfFooter(doc),
  });

  _pdfSignature(doc, doc.lastAutoTable.finalY);
  _openAndPrint(doc);
}

// ============================================================
// 4. GENDER-BASED NUTRITION COMPARISON
// ============================================================
async function generateGenderComparisonPDF() {
  const { jsPDF } = window.jspdf;
  const doc = new jsPDF("landscape");

  const pageWidth = doc.internal.pageSize.getWidth();

  let startY = _pdfHeader(
    doc,
    "Gender-Based Nutrition Comparison",
    "BMI distribution and at-risk breakdown by gender",
  );

  // ── Capture gender bar chart ───────────────────────────
  const canvas = document.getElementById("genderBmiChart");
  if (canvas) {
    try {
      const imgData = canvas.toDataURL("image/png");
      const chartW = 160;
      const chartH = 70;
      doc.addImage(
        imgData,
        "PNG",
        pageWidth / 2 - chartW / 2,
        startY,
        chartW,
        chartH,
      );
      startY += chartH + 10;
    } catch (e) {}
  }

  // ── Gender summary — read from visible .rpt-gender-col elements ──
  const genderCols = document.querySelectorAll(".rpt-gender-col");
  const genderBody = [];
  genderCols.forEach((col) => {
    const rawLabel =
      col.querySelector(".rpt-gender-label")?.textContent?.trim() || "";
    // Strip non-ASCII symbols (♂ ♀) — not supported by jsPDF default font
    const label = rawLabel.replace(/[^\x00-\x7F]/g, "").trim();
    const avgBmi =
      col.querySelector(".rpt-gender-avg strong")?.textContent?.trim() || "—";
    const atRisk =
      col.querySelector(".rpt-gender-atrisk strong")?.textContent?.trim() ||
      "—";
    const total =
      col
        .querySelector(".rpt-gender-atrisk")
        ?.textContent?.match(/\/\s*(\d+)/)?.[1] || "—";
    genderBody.push([label, total, avgBmi, atRisk]);
  });

  if (genderBody.length === 0) {
    doc.setFontSize(9);
    doc.setTextColor(150);
    doc.text("No gender data available.", 14, startY + 6);
    doc.setTextColor(0);
    _openAndPrint(doc);
    return;
  }

  doc.autoTable({
    head: [["Gender", "Total Residents", "Avg BMI", "At-Risk"]],
    body: genderBody,
    ..._autoTableDefaults(doc, startY),
    columnStyles: {
      0: { fontStyle: "bold" },
      1: { halign: "center" },
      2: { halign: "center" },
      3: { halign: "center" },
    },
    didDrawPage: () => _pdfFooter(doc),
  });

  _pdfSignature(doc, doc.lastAutoTable.finalY);
  _openAndPrint(doc);
}

// ============================================================
// 5. MONTHLY AT-RISK COMPARISON
// ============================================================
async function generateAtRiskTrendPDF() {
  const { jsPDF } = window.jspdf;
  const doc = new jsPDF("landscape");

  const pageWidth = doc.internal.pageSize.getWidth();

  let startY = _pdfHeader(
    doc,
    "Monthly At-Risk Comparison",
    "At-risk resident count trend over the last 6 months",
  );

  // ── Capture line chart ─────────────────────────────────
  const canvas = document.getElementById("atRiskTrendChart");
  if (canvas) {
    try {
      const imgData = canvas.toDataURL("image/png");
      const chartW = 200;
      const chartH = 75;
      doc.addImage(
        imgData,
        "PNG",
        pageWidth / 2 - chartW / 2,
        startY,
        chartW,
        chartH,
      );
      startY += chartH + 10;
    } catch (e) {}
  }

  // ── Pull data from New Residents table (reuse same pattern) ──
  // At-risk data is rendered in the card — read from chart labels/data
  // We grab from the statNewResidentsTable structure as reference
  // but at-risk uses its own rendered card; read from DOM chart tooltip
  // Safest: pull from the "Monthly At-Risk Comparison" card text content
  // Actually we read from the chart canvas data attributes set via PHP
  // Best approach: read the rendered table if it exists, else use inline data
  const atRiskCard = document.querySelector(
    '.rpt-card[data-title="monthly at-risk comparison"]',
  );
  const cardDesc =
    atRiskCard?.querySelector(".rpt-card-meta")?.textContent || "";

  // The data is baked into the Chart.js instance — read it back
  const chartInstance = window._chartInstances?.atRisk || null;
  let tableBody = [];

  if (atRiskTrendInstance && atRiskTrendInstance.data) {
    const labels = atRiskTrendInstance.data.labels || [];
    const data = atRiskTrendInstance.data.datasets[0]?.data || [];
    tableBody = labels.map((lbl, i) => [lbl, data[i] ?? 0]);
  }

  if (tableBody.length === 0) {
    tableBody = [["No data available", "—"]];
  }

  doc.autoTable({
    head: [["Month", "At-Risk Residents"]],
    body: tableBody,
    ..._autoTableDefaults(doc, startY),
    columnStyles: {
      0: { fontStyle: "bold" },
      1: { halign: "center" },
    },
    didDrawPage: () => _pdfFooter(doc),
  });

  _pdfSignature(doc, doc.lastAutoTable.finalY);
  _openAndPrint(doc);
}

// ============================================================
// 6. NEW RESIDENTS REGISTERED
// ============================================================
async function generateNewResidentsPDF() {
  const { jsPDF } = window.jspdf;
  const doc = new jsPDF("landscape");

  const pageWidth = doc.internal.pageSize.getWidth();

  let startY = _pdfHeader(
    doc,
    "New Residents Registered",
    "Monthly enrollment count over the last 6 months",
  );

  // ── Capture bar chart ──────────────────────────────────
  const canvas = document.getElementById("newResidentsChart");
  if (canvas) {
    try {
      const imgData = canvas.toDataURL("image/png");
      const chartW = 200;
      const chartH = 75;
      doc.addImage(
        imgData,
        "PNG",
        pageWidth / 2 - chartW / 2,
        startY,
        chartW,
        chartH,
      );
      startY += chartH + 10;
    } catch (e) {}
  }

  // ── Pull from New Residents table ──────────────────────
  const table = document.querySelector("#statNewResidentsTable");
  if (!table) {
    alert("New residents table not found.");
    return;
  }

  // Build body from table rows — skip the visual bar column
  const rows = [];
  table.querySelectorAll("tbody tr").forEach((tr) => {
    if (tr.style.display === "none") return;
    const cells = tr.querySelectorAll("td");
    if (cells.length >= 2) {
      rows.push([cells[0].textContent.trim(), cells[1].textContent.trim()]);
    }
  });

  // Compute totals
  const totalNew = rows.reduce((s, r) => s + (parseInt(r[1]) || 0), 0);

  doc.autoTable({
    head: [["Month", "New Registrations"]],
    body: rows,
    ..._autoTableDefaults(doc, startY),
    columnStyles: {
      0: { fontStyle: "bold" },
      1: { halign: "center" },
    },
    didDrawPage: () => _pdfFooter(doc),
  });

  // ── Totals row footer ──────────────────────────────────
  const finalY = doc.lastAutoTable.finalY;
  doc.setFontSize(9);
  doc.setFont("helvetica", "bold");
  doc.setTextColor(26, 31, 54);
  doc.text("Total New Registrations (6 months): " + totalNew, 14, finalY + 8);
  doc.setFont("helvetica", "normal");
  doc.setTextColor(0);

  _pdfSignature(doc, finalY + 8);
  _openAndPrint(doc);
}

// ============================================================
// EXISTING: HEALTH RECORDS PDF (already in use)
// ============================================================
async function generateHealthRecordsPDF() {
  const { jsPDF } = window.jspdf;
  const doc = new jsPDF("landscape");

  const pageWidth = doc.internal.pageSize.getWidth();
  const pageHeight = doc.internal.pageSize.getHeight();
  const leftMargin = 14;
  const rightMargin = 14;

  const rawTitle =
    document
      .querySelector("#statRecordsTable")
      ?.closest(".rpt-stat-section")
      ?.querySelector(".rpt-stat-title")
      ?.textContent?.trim() || "Health Records Report";
  const monthTitle = rawTitle.replace("Health Records ·", "").trim();

  let startY = _pdfHeader(
    doc,
    "Health Records Report — " + monthTitle,
    "Monthly health record data for all residents",
  );

  const table = document.querySelector("#statRecordsTable");
  if (!table) return alert("Table not found.");

  const tableClone = table.cloneNode(true);
  tableClone.querySelectorAll("tbody tr").forEach((tr) => {
    if (tr.style.display === "none") tr.remove();
    tr.querySelectorAll("td").forEach((td) => {
      const metric = td.querySelector(".metric");
      if (metric) td.textContent = metric.textContent.trim();
      const bmiVal = td.querySelector(".rpt-bmi-val");
      if (bmiVal) td.textContent = bmiVal.textContent.trim();
    });
  });

  doc.autoTable({
    html: tableClone,
    ..._autoTableDefaults(doc, startY),
    didDrawPage: () => _pdfFooter(doc),
  });

  _pdfSignature(doc, doc.lastAutoTable.finalY);
  _openAndPrint(doc);
}

// ============================================================
// PREVIEW: Scroll helpers — smooth scroll to the relevant
// section in the Statistics tab
// ============================================================
function previewScrollTo(tabId, sectionSelector) {
  const tabBtns = document.querySelectorAll(".rpt-tab-btn");
  const tabMap = { statistics: 1, priority: 2, unassessed: 3, all: 0 };
  const btnIdx = tabMap[tabId] ?? 1;

  switchTab(tabId, tabBtns[btnIdx]);

  if (sectionSelector) {
    setTimeout(() => {
      const el = document.querySelector(sectionSelector);
      if (el) el.scrollIntoView({ behavior: "smooth", block: "start" });
    }, 120);
  }
}
