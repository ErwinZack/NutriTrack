// assets/js/reports_municipal_pdf.js
// Municipal PDF Report Generator — follows user_management_municipal_pdf.js pattern

// ── Shared Header ──
function _munHdr(doc, subtitle, desc) {
  const pw = doc.internal.pageSize.getWidth();
  const L = 14,
    R = 14;

  const sysLogo = document.getElementById("logoSystem");
  const dohLogo = document.getElementById("logoDOH");
  if (sysLogo) doc.addImage(sysLogo, "PNG", L, 8, 28, 28);
  if (dohLogo) doc.addImage(dohLogo, "PNG", pw - R - 28, 8, 28, 28);

  doc.setFont("helvetica", "bold");
  doc.setFontSize(17);
  doc.setTextColor(26, 31, 54);
  doc.text("Municipality of Calasiao", pw / 2, 14, { align: "center" });

  doc.setFont("helvetica", "normal");
  doc.setFontSize(10);
  doc.setTextColor(100);
  doc.text("Calasiao, Pangasinan", pw / 2, 20, { align: "center" });

  doc.setFont("helvetica", "bold");
  doc.setFontSize(12);
  doc.setTextColor(26, 31, 54);
  doc.text("NutriTrack Nutrition Monitoring System", pw / 2, 27, {
    align: "center",
  });

  doc.setFont("helvetica", "normal");
  doc.setFontSize(11);
  doc.setTextColor(60, 80, 120);
  doc.text(subtitle, pw / 2, 33, { align: "center" });

  if (desc) {
    doc.setFontSize(9);
    doc.setTextColor(130);
    doc.text(desc, pw / 2, 39, { align: "center" });
  }

  doc.setDrawColor(37, 99, 235);
  doc.setLineWidth(0.7);
  doc.line(L, 43, pw - R, 43);

  const date = new Date().toLocaleDateString("en-PH", {
    year: "numeric",
    month: "long",
    day: "numeric",
  });
  doc.setFontSize(8.5);
  doc.setTextColor(120);
  doc.text("Generated on: " + date, L, 49);
  doc.setTextColor(0);

  return 55; // startY
}

// ── Shared Footer per page ──
function _munFtr(doc) {
  return () => {
    const pw = doc.internal.pageSize.getWidth();
    const ph = doc.internal.pageSize.getHeight();
    doc.setFontSize(8);
    doc.setTextColor(150);
    doc.text("Page " + doc.internal.getNumberOfPages(), pw - 34, ph - 7);
    doc.text("NutriTrack © " + new Date().getFullYear(), 14, ph - 7);
    doc.setTextColor(0);
  };
}

// ── Shared Signature Block ──
function _munSig(doc, y) {
  const pw = doc.internal.pageSize.getWidth();
  const ph = doc.internal.pageSize.getHeight();
  if (y + 30 > ph) {
    doc.addPage();
    y = 40;
  }
  const rx = pw - 14 - 70;

  doc.setFontSize(9);
  doc.setTextColor(80);
  doc.text("Prepared by:", 14, y);
  doc.text("__________________________", 14, y + 8);
  doc.setFont("helvetica", "bold");
  doc.text(window.MUNICIPAL_ADMIN_NAME || "Municipal Admin", 14, y + 13);
  doc.setFont("helvetica", "normal");
  doc.setFontSize(9);
  doc.setTextColor(120);
  doc.text("Municipal Superadmin", 14, y + 18);

  doc.setTextColor(80);
  doc.text("Noted by:", rx, y);
  doc.text("__________________________", rx, y + 8);
  doc.text("Municipal Health Officer", rx, y + 14);
  doc.setTextColor(0);
}

// ── Open + Auto-Print ──
function _munOpen(doc) {
  const url = URL.createObjectURL(doc.output("blob"));
  const win = window.open(url);
  if (win)
    win.onload = () => {
      win.focus();
      win.print();
    };
}

// ── Shared autoTable styles ──
const _munTblStyles = {
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
};

// ══════════════════════════════════════════════════
// #1 Town-Wide Monthly Nutrition Report
// ══════════════════════════════════════════════════
async function generateTownWideNutritionPDF() {
  const { jsPDF } = window.jspdf;
  const doc = new jsPDF("landscape");
  const pageWidth = doc.internal.pageSize.getWidth();
  const leftMargin = 14;
  const stats = window.RPT_MONTHLY_STATS || {
    uw: 0,
    normal: 0,
    ow: 0,
    obese: 0,
    total: 0,
  };
  const month = window.RPT_SELECTED_MONTH || "";

  let startY = _munHdr(
    doc,
    "Town-Wide Monthly Nutrition Report",
    "Aggregated BMI status across all barangays · " + month,
  );

  const categories = [
    { label: "Underweight", color: [8, 145, 178], val: stats.uw },
    { label: "Normal", color: [22, 163, 74], val: stats.normal },
    { label: "Overweight", color: [217, 119, 6], val: stats.ow },
    { label: "Obese", color: [220, 38, 38], val: stats.obese },
  ];

  // ── KPI Boxes ──
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

  // ── Horizontal Bars ──
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

  // ── Summary Table ──
  const total = stats.total || 1;
  const pct = (n) => (total > 0 ? ((n / total) * 100).toFixed(1) + "%" : "0%");
  doc.autoTable({
    head: [["BMI Status", "Count", "Percentage"]],
    body: [
      ["Underweight", stats.uw, pct(stats.uw)],
      ["Normal", stats.normal, pct(stats.normal)],
      ["Overweight", stats.ow, pct(stats.ow)],
      ["Obese", stats.obese, pct(stats.obese)],
    ],
    startY,
    margin: { left: leftMargin, right: 14 },
    styles: _munTblStyles.styles,
    headStyles: _munTblStyles.headStyles,
    alternateRowStyles: _munTblStyles.alternateRowStyles,
    columnStyles: {
      0: { fontStyle: "bold" },
      1: { halign: "center" },
      2: { halign: "center" },
    },
    didDrawPage: _munFtr(doc),
  });

  _munSig(doc, doc.lastAutoTable.finalY + 20);
  _munOpen(doc);
}

// ══════════════════════════════════════════════════
// #2 Per-Barangay Comparison
// ══════════════════════════════════════════════════
async function generateBarangayComparisonPDF() {
  const { jsPDF } = window.jspdf;
  const doc = new jsPDF("landscape");
  const pw = doc.internal.pageSize.getWidth();

  let y = _munHdr(
    doc,
    "Per-Barangay Comparison Report",
    "Residents, records, and at-risk % ranked by risk level",
  );

  const canvas = document.getElementById("barangayCompareChart");
  if (canvas) {
    doc.addImage(canvas.toDataURL("image/png"), "PNG", 14, y, pw - 28, 65);
    y += 73;
  }

  const data = (window.RPT_BARANGAY_DATA || []).map((b) => [
    b.barangay_name,
    b.residents,
    b.records,
    b.assessed,
    b.at_risk,
    b.at_risk_pct + "%",
    b.active_bhws,
  ]);

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
    body: data,
    startY: y,
    margin: { left: 14, right: 14 },
    styles: _munTblStyles.styles,
    headStyles: _munTblStyles.headStyles,
    alternateRowStyles: _munTblStyles.alternateRowStyles,
    didDrawPage: _munFtr(doc),
  });

  _munSig(doc, doc.lastAutoTable.finalY + 20);
  _munOpen(doc);
}

// ══════════════════════════════════════════════════
// #3 Gender-Based Nutrition Comparison
// ══════════════════════════════════════════════════
async function generateGenderComparisonPDF() {
  const { jsPDF } = window.jspdf;
  const doc = new jsPDF("landscape");
  const pw = doc.internal.pageSize.getWidth();

  let y = _munHdr(
    doc,
    "Gender-Based Nutrition Comparison",
    "Town-wide BMI split by gender across all residents",
  );

  const canvas = document.getElementById("genderBmiChart");
  if (canvas) {
    doc.addImage(canvas.toDataURL("image/png"), "PNG", 14, y, pw - 28, 75);
    y += 83;
  }

  const gs = window.RPT_GENDER_STATS || {};
  const body = Object.entries(gs).map(([g, s]) => [
    g,
    s.total,
    s.avg_bmi,
    s.uw,
    s.normal,
    s.ow,
    s.obese,
    s.at_risk,
  ]);

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
    body,
    startY: y,
    margin: { left: 14, right: 14 },
    styles: _munTblStyles.styles,
    headStyles: _munTblStyles.headStyles,
    alternateRowStyles: _munTblStyles.alternateRowStyles,
    didDrawPage: _munFtr(doc),
  });

  _munSig(doc, doc.lastAutoTable.finalY + 20);
  _munOpen(doc);
}

// ══════════════════════════════════════════════════
// #4 New Residents Registered
// ══════════════════════════════════════════════════
async function generateNewResidentsPDF() {
  const { jsPDF } = window.jspdf;
  const doc = new jsPDF("landscape");
  const pw = doc.internal.pageSize.getWidth();

  let y = _munHdr(
    doc,
    "New Residents Registered",
    "Monthly enrollment count — last 6 months, town-wide",
  );

  const canvas = document.getElementById("newResidentsChart");
  if (canvas) {
    doc.addImage(canvas.toDataURL("image/png"), "PNG", 14, y, pw - 28, 75);
    y += 83;
  }

  const data = (window.RPT_NEW_RESIDENTS_DATA || []).map((r) => [
    r.label,
    r.count,
  ]);
  doc.autoTable({
    head: [["Month", "New Registrations"]],
    body: data,
    startY: y,
    margin: { left: 14, right: 14 },
    styles: _munTblStyles.styles,
    headStyles: _munTblStyles.headStyles,
    alternateRowStyles: _munTblStyles.alternateRowStyles,
    didDrawPage: _munFtr(doc),
  });

  _munSig(doc, doc.lastAutoTable.finalY + 20);
  _munOpen(doc);
}

// ══════════════════════════════════════════════════
// #5 Monthly At-Risk Trend
// ══════════════════════════════════════════════════
async function generateAtRiskTrendPDF() {
  const { jsPDF } = window.jspdf;
  const doc = new jsPDF("landscape");
  const pw = doc.internal.pageSize.getWidth();

  let y = _munHdr(
    doc,
    "Monthly At-Risk Trend",
    "Month-over-month at-risk resident count — town-wide, last 6 months",
  );

  const canvas = document.getElementById("atRiskTrendChart");
  if (canvas) {
    doc.addImage(canvas.toDataURL("image/png"), "PNG", 14, y, pw - 28, 85);
    y += 93;
  }

  const data = (window.RPT_AT_RISK_DATA || []).map((r) => [r.label, r.count]);
  doc.autoTable({
    head: [["Month", "At-Risk Residents"]],
    body: data,
    startY: y,
    margin: { left: 14, right: 14 },
    styles: _munTblStyles.styles,
    headStyles: _munTblStyles.headStyles,
    alternateRowStyles: _munTblStyles.alternateRowStyles,
    didDrawPage: _munFtr(doc),
  });

  _munSig(doc, doc.lastAutoTable.finalY + 20);
  _munOpen(doc);
}

// ══════════════════════════════════════════════════
// #6 BHW Workforce Coverage
// ══════════════════════════════════════════════════
async function generateBhwCoveragePDF() {
  const { jsPDF } = window.jspdf;
  const doc = new jsPDF("landscape");
  const pw = doc.internal.pageSize.getWidth();

  let y = _munHdr(
    doc,
    "BHW Workforce Coverage Report",
    "Active BHWs vs resident count per barangay — staffing ratio",
  );

  const canvas = document.getElementById("bhwCoverageChart");
  if (canvas) {
    doc.addImage(canvas.toDataURL("image/png"), "PNG", 14, y, pw - 28, 75);
    y += 83;
  }

  const data = (window.RPT_BARANGAY_DATA || []).map((b) => [
    b.barangay_name,
    b.residents,
    b.active_bhws,
  ]);

  doc.autoTable({
    head: [["Barangay", "Residents", "Active BHWs"]],
    body: data,
    startY: y,
    margin: { left: 14, right: 14 },
    styles: _munTblStyles.styles,
    headStyles: _munTblStyles.headStyles,
    alternateRowStyles: _munTblStyles.alternateRowStyles,
    didDrawPage: _munFtr(doc),
  });

  _munSig(doc, doc.lastAutoTable.finalY + 20);
  _munOpen(doc);
}
