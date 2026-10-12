// master_report_pdf.js
// Generates a master summary PDF report across all barangay health data
async function generateMasterReportPDF() {

    // ── CRITICAL: Switch to Statistics tab so Chart.js renders all canvases ──
    const statTab = document.querySelector('.rpt-tab-btn[onclick*="statistics"]');
    if (statTab && !document.getElementById('tab-statistics')?.classList.contains('active')) {
        switchTab('statistics', statTab);
        await new Promise(resolve => setTimeout(resolve, 700));
    }

    const { jsPDF } = window.jspdf;
    const doc = new jsPDF('landscape');

    const pageWidth   = doc.internal.pageSize.getWidth();
    const pageHeight  = doc.internal.pageSize.getHeight();
    const leftMargin  = 14;
    const rightMargin = 14;

    const monthInput = document.querySelector('#tab-statistics input[type="month"]')
                    || document.querySelector('input[type="month"]');
    const monthVal   = monthInput?.value || '';
    const monthLabel = monthVal
        ? new Date(monthVal + '-01').toLocaleDateString('en-PH', { month: 'long', year: 'numeric' })
        : new Date().toLocaleDateString('en-PH', { month: 'long', year: 'numeric' });

    // EXACT SAME SHARED HELPERS AS reports_pdf.js

    function _pdfHeader(reportTitle, reportSubtitle) {
        const systemLogo = document.getElementById("logoSystem");
        const dohLogo    = document.getElementById("logoDOH");
        if (systemLogo) doc.addImage(systemLogo, 'PNG', leftMargin, 8, 28, 28);
        if (dohLogo)    doc.addImage(dohLogo,    'PNG', pageWidth - rightMargin - 28, 8, 28, 28);

        doc.setFont("helvetica", "bold");
        doc.setFontSize(17);
        doc.setTextColor(26, 31, 54);
        doc.text("Barangay Health Center", pageWidth / 2, 14, { align: "center" });

        doc.setFont("helvetica", "normal");
        doc.setFontSize(10);
        doc.setTextColor(100);
        doc.text("Barangay " + (window.CURRENT_BARANGAY_NAME || "Health Center") + ", Calasiao, Pangasinan", pageWidth / 2, 20, { align: "center" });

        doc.setFont("helvetica", "bold");
        doc.setFontSize(12);
        doc.setTextColor(26, 31, 54);
        doc.text("NutriTrack Nutrition Monitoring System", pageWidth / 2, 27, { align: "center" });

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

        const formattedDate = new Date().toLocaleDateString('en-PH', {
            year: 'numeric', month: 'long', day: 'numeric'
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
            pageHeight - 7
        );
        doc.text("NutriTrack © " + new Date().getFullYear(), leftMargin, pageHeight - 7);
        doc.setTextColor(0);
    }

    function _pdfSignature(startY) {
        let y = startY + 20;
        if (y + 30 > pageHeight) { doc.addPage(); y = 40; }

        doc.setFontSize(9);
        doc.setTextColor(80);
        const rightX = pageWidth - rightMargin - 70;

                doc.text("Prepared by:",               leftMargin, y);
        doc.text("__________________________", leftMargin, y + 8);
        doc.setFont("helvetica", "bold");
        doc.text(window.CURRENT_WORKER_NAME || "Health Worker", leftMargin, y + 13);
        doc.setFont("helvetica", "normal");
        doc.setFontSize(9);
        doc.setTextColor(120);
        doc.text("Health Worker", leftMargin, y + 18);
        doc.setFontSize(9);
        doc.setTextColor(80);

        doc.text("Noted by:",                  rightX, y);
        doc.text("__________________________", rightX, y + 8);
        doc.text("Barangay Health Officer",    rightX, y + 14);

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
                fillColor: [208, 208, 208],  // ← changed from [255, 255, 255]
                textColor: [20, 20, 20],
                fontStyle: 'bold',
                fontSize: 9,
                lineColor: [120, 120, 120],
                lineWidth: 0.4,
            },
            alternateRowStyles: { fillColor: [245, 245, 245] },
            didDrawPage: () => _pdfFooter(),
        };
    }

    // Chart capture — with page overflow guard
    function _captureChart(canvasId, startY, chartW, chartH, fallbackTitle, fallbackSubtitle) {
        if (startY + chartH > pageHeight - 20) {
            doc.addPage();
            startY = _pdfHeader(fallbackTitle || "Chart", fallbackSubtitle || "");
        }
        const canvas = document.getElementById(canvasId);
        if (!canvas) return startY;
        try {
            const imgData = canvas.toDataURL('image/png');
            doc.addImage(imgData, 'PNG', pageWidth / 2 - chartW / 2, startY, chartW, chartH);
            return startY + chartH + 10;
        } catch(e) {
            console.warn('Chart capture failed:', canvasId, e);
            return startY;
        }
    }

    // ─────────────────────────────────────────────────────
    // PAGE 1 — COVER PAGE
    // ─────────────────────────────────────────────────────
    const systemLogo = document.getElementById("logoSystem");
    const dohLogo    = document.getElementById("logoDOH");

    if (systemLogo) doc.addImage(systemLogo, 'PNG', pageWidth / 2 - 22, 18, 44, 44);

    doc.setFont("helvetica", "bold");
    doc.setFontSize(22);
    doc.setTextColor(26, 31, 54);
    doc.text("Monthly Health Summary Report", pageWidth / 2, 75, { align: "center" });

    doc.setFont("helvetica", "normal");
    doc.setFontSize(13);
    doc.setTextColor(60, 80, 120);
    doc.text(monthLabel, pageWidth / 2, 85, { align: "center" });

    doc.setDrawColor(37, 99, 235);
    doc.setLineWidth(1);
    doc.line(leftMargin + 40, 91, pageWidth - rightMargin - 40, 91);

    doc.setFontSize(11);
    doc.setTextColor(26, 31, 54);
    doc.text("Barangay Health Center", pageWidth / 2, 102, { align: "center" });
    doc.setFont("helvetica", "normal");
    doc.setFontSize(10);
    doc.setTextColor(100);
    doc.text("Barangay " + (window.CURRENT_BARANGAY_NAME || "Health Center") + ", Calasiao, Pangasinan", pageWidth / 2, 110, { align: "center" });
    doc.text("NutriTrack Nutrition Monitoring System", pageWidth / 2, 118, { align: "center" });

    doc.setFillColor(248, 250, 255);
    doc.setDrawColor(37, 99, 235);
    doc.setLineWidth(0.3);
    doc.roundedRect(leftMargin + 60, 128, pageWidth - leftMargin - rightMargin - 120, 50, 3, 3, 'FD');

    const formattedDate = new Date().toLocaleDateString('en-PH', { year: 'numeric', month: 'long', day: 'numeric' });
    doc.setFont("helvetica", "normal");
    doc.setFontSize(9.5);
    doc.setTextColor(60, 80, 120);
    doc.text("Report Period:",  pageWidth / 2, 140, { align: "center" });
    doc.setFont("helvetica", "bold");
    doc.setTextColor(26, 31, 54);
    doc.text(monthLabel,        pageWidth / 2, 148, { align: "center" });
    doc.setFont("helvetica", "normal");
    doc.setTextColor(60, 80, 120);
    doc.text("Generated on:",   pageWidth / 2, 158, { align: "center" });
    doc.setFont("helvetica", "bold");
    doc.setTextColor(26, 31, 54);
    doc.text(formattedDate,     pageWidth / 2, 166, { align: "center" });

    if (dohLogo) doc.addImage(dohLogo, 'PNG', pageWidth - rightMargin - 28, pageHeight - 40, 24, 24);
    doc.setFontSize(8);
    doc.setTextColor(150);
    doc.text("NutriTrack © " + new Date().getFullYear(), pageWidth / 2, pageHeight - 7, { align: "center" });
    doc.setTextColor(0);

    // ─────────────────────────────────────────────────────
    // PAGE 2 — MONTHLY NUTRITION REPORT
    // Same layout as generateMonthlyNutritionPDF()
    // ─────────────────────────────────────────────────────
    doc.addPage();
    const monthLabelDesc = document.querySelector(
        '#statCardsGrid .rpt-card[data-title="monthly nutrition report"] .rpt-card-desc'
    )?.textContent?.match(/for (.+)\./)?.[1] || monthLabel;

    let startY = _pdfHeader("Monthly Nutrition Report", "Summary for " + monthLabelDesc);

    const categories = [
        { label: "Underweight", color: [8, 145, 178],  key: "uw" },
        { label: "Normal",      color: [22, 163, 74],  key: "nm" },
        { label: "Overweight",  color: [217, 119, 6],  key: "ow" },
        { label: "Obese",       color: [220, 38, 38],  key: "ob" },
    ];

    const barRows = document.querySelectorAll(
        '#statCardsGrid .rpt-card[data-title="monthly nutrition report"] .rpt-stat-bar-row'
    );
    const counts = {};
    let total = 0;
    barRows.forEach((row, i) => {
        const text  = row.querySelector('.rpt-stat-bar-meta')?.textContent || '';
        const match = text.match(/(\d+)\s*\((\d+)%\)/);
        const count = match ? parseInt(match[1]) : 0;
        counts[categories[i]?.key] = count;
        total += count;
    });

    // KPI boxes
    const boxW = (pageWidth - leftMargin * 2 - 12) / 4;
    let bx = leftMargin;
    categories.forEach(cat => {
        const cnt = counts[cat.key] || 0;
        const pct = total > 0 ? Math.round((cnt / total) * 100) : 0;
        doc.setFillColor(...cat.color);
        doc.roundedRect(bx, startY, boxW, 22, 3, 3, 'F');
        doc.setTextColor(255);
        doc.setFont("helvetica", "bold");
        doc.setFontSize(16);
        doc.text(String(cnt), bx + boxW / 2, startY + 11, { align: "center" });
        doc.setFontSize(8);
        doc.setFont("helvetica", "normal");
        doc.text(cat.label + " (" + pct + "%)", bx + boxW / 2, startY + 18, { align: "center" });
        bx += boxW + 4;
    });
    doc.setTextColor(0);
    startY += 30;

    // Horizontal bar chart (native PDF)
    const barMaxW = pageWidth - leftMargin * 2 - 40;
    categories.forEach((item, i) => {
        const cnt = counts[item.key] || 0;
        const pct = total > 0 ? cnt / total : 0;
        const y   = startY + i * 12;
        doc.setFontSize(8);
        doc.setTextColor(80);
        doc.text(item.label, leftMargin, y + 3.5);
        doc.setFillColor(230, 235, 245);
        doc.roundedRect(leftMargin + 38, y, barMaxW, 6, 1, 1, 'F');
        if (pct > 0) {
            doc.setFillColor(...item.color);
            doc.roundedRect(leftMargin + 38, y, barMaxW * pct, 6, 1, 1, 'F');
        }
        doc.setTextColor(...item.color);
        doc.text(cnt + " (" + Math.round(pct * 100) + "%)", pageWidth - leftMargin - 2, y + 3.5, { align: 'right' });
    });
    doc.setTextColor(0);
    startY += categories.length * 12 + 8;

    // Status breakdown table
    const monthlyTableBody = categories.map(cat => {
        const cnt = counts[cat.key] || 0;
        const pct = total > 0 ? Math.round((cnt / total) * 100) : 0;
        return [cat.label, cnt, pct + "%"];
    });
    doc.autoTable({
        head: [["BMI Status", "Count", "Percentage"]],
        body: monthlyTableBody,
        ..._autoTableDefaults(startY),
        columnStyles: {
            0: { fontStyle: 'bold' },
            1: { halign: 'center' },
            2: { halign: 'center' },
        },
        bodyStyles: { textColor: [26, 31, 54] },
              didParseCell: (data) => {
            if (data.section === 'body' && data.column.index === 0) {
                data.cell.styles.fontStyle = 'bold';
            }
        },
        didDrawPage: () => _pdfFooter(),
    });

    // ─────────────────────────────────────────────────────
    // PAGE 3 — BMI DISTRIBUTION (ALL-TIME)
    // Same layout as generateBmiDistributionPDF()
    // ─────────────────────────────────────────────────────
    doc.addPage();
    startY = _pdfHeader("BMI Distribution Report (All-time)", "Based on latest health record per resident");

    // Doughnut chart
    startY = _captureChart('statBmiChart', startY, 120, 70,
        "BMI Distribution Report (All-time)", "Based on latest health record per resident");

    // Pull from stat bar rows
    const distMeta = [
        { label: "Underweight", range: "BMI < 18.5"  },
        { label: "Normal",      range: "18.5 - 24.9" },
        { label: "Overweight",  range: "25.0 - 29.9" },
        { label: "Obese",       range: "BMI >= 30"   },
    ];
    const distRows = [];
    document.querySelectorAll('.rpt-stat-bar-row').forEach((row, i) => {
        if (i >= 4) return;
        const text  = row.querySelector('.rpt-stat-bar-meta span:last-child')?.textContent?.trim() || '';
        const match = text.match(/^(\d+)/);
        const count = match ? parseInt(match[1]) : 0;
        distRows.push([distMeta[i].label, distMeta[i].range, count]);
    });
    const distTotal = distRows.reduce((s, r) => s + r[2], 0);
    doc.autoTable({
        head: [["BMI Status", "BMI Range", "Resident Count", "Percentage"]],
        body: distRows.map(r => [r[0], r[1], r[2], distTotal > 0 ? Math.round((r[2]/distTotal)*100)+"%" : "0%"]),
        ..._autoTableDefaults(startY),
        columnStyles: {
            0: { fontStyle: 'bold' },
            2: { halign: 'center' },
            3: { halign: 'center' },
        },
             didParseCell: (data) => {
            if (data.section === 'body' && data.column.index === 0) {
                data.cell.styles.fontStyle = 'bold';
            }
        },
        didDrawPage: () => _pdfFooter(),
    });
    _pdfSignature(doc.lastAutoTable.finalY);

    // ─────────────────────────────────────────────────────
    // PAGE 4 — AGE GROUP ANALYSIS
    // Same layout as generateAgeGroupPDF()
    // ─────────────────────────────────────────────────────
    doc.addPage();
    startY = _pdfHeader("Age Group Analysis Report", "Resident count by age group — based on latest health records");

    const ageRows = [];
    document.querySelectorAll('.rpt-age-bar-row').forEach(row => {
        const label = row.querySelector('.rpt-age-bar-label')?.textContent?.trim() || '';
        const count = parseInt(row.querySelector('.rpt-age-bar-count')?.textContent?.trim()) || 0;
        if (label) ageRows.push([label, count]);
    });
    const ageTotal = ageRows.reduce((s, r) => s + r[1], 0);
    if (ageRows.length > 0) {
        doc.autoTable({
            head: [["Age Group", "Resident Count", "Percentage"]],
            body: ageRows.map(r => [r[0], r[1], ageTotal > 0 ? Math.round((r[1]/ageTotal)*100)+"%" : "0%"]),
            ..._autoTableDefaults(startY),
            columnStyles: {
                0: { fontStyle: 'bold' },
                1: { halign: 'center' },
                2: { halign: 'center' },
            },
            didDrawPage: () => _pdfFooter(),
        });
        _pdfSignature(doc.lastAutoTable.finalY);
    }

    // ─────────────────────────────────────────────────────
    // PAGE 5 — GENDER-BASED NUTRITION COMPARISON
    // Same layout as generateGenderComparisonPDF()
    // ─────────────────────────────────────────────────────
    doc.addPage();
    startY = _pdfHeader("Gender-Based Nutrition Comparison", "BMI distribution and at-risk breakdown by gender");

    // Gender bar chart
    startY = _captureChart('genderBmiChart', startY, 160, 70,
        "Gender-Based Nutrition Comparison", "BMI distribution and at-risk breakdown by gender");

    // Gender summary from .rpt-gender-col
    const genderCols = document.querySelectorAll('.rpt-gender-col');
    const genderBody = [];
    genderCols.forEach(col => {
        const rawLabel = col.querySelector('.rpt-gender-label')?.textContent?.trim() || '';
        const label    = rawLabel.replace(/[^\x00-\x7F]/g, '').trim();
        const avgBmi   = col.querySelector('.rpt-gender-avg strong')?.textContent?.trim() || '—';
        const atRisk   = col.querySelector('.rpt-gender-atrisk strong')?.textContent?.trim() || '—';
        const total    = col.querySelector('.rpt-gender-atrisk')?.textContent?.match(/\/\s*(\d+)/)?.[1] || '—';
        genderBody.push([label, total, avgBmi, atRisk]);
    });
    if (genderBody.length > 0) {
        doc.autoTable({
            head: [["Gender", "Total Residents", "Avg BMI", "At-Risk"]],
            body: genderBody,
            ..._autoTableDefaults(startY),
            columnStyles: {
                0: { fontStyle: 'bold' },
                1: { halign: 'center' },
                2: { halign: 'center' },
                3: { halign: 'center' },
            },
            didDrawPage: () => _pdfFooter(),
        });
        _pdfSignature(doc.lastAutoTable.finalY);
    }

    // ─────────────────────────────────────────────────────
    // PAGE 6 — MONTHLY AT-RISK COMPARISON
    // Same layout as generateAtRiskTrendPDF()
    // ─────────────────────────────────────────────────────
    doc.addPage();
    startY = _pdfHeader("Monthly At-Risk Comparison", "At-risk resident count trend over the last 6 months");

    // Line chart
    startY = _captureChart('atRiskTrendChart', startY, 200, 75,
        "Monthly At-Risk Comparison", "At-risk resident count trend over the last 6 months");

    // Data from Chart.js instance
    let atRiskTableBody = [];
    if (typeof atRiskTrendInstance !== 'undefined' && atRiskTrendInstance?.data) {
        const labels    = atRiskTrendInstance.data.labels || [];
        const chartData = atRiskTrendInstance.data.datasets[0]?.data || [];
        atRiskTableBody = labels.map((lbl, i) => [lbl, chartData[i] ?? 0]);
    }
    if (atRiskTableBody.length === 0) atRiskTableBody = [["No data available", "—"]];

    doc.autoTable({
        head: [["Month", "At-Risk Residents"]],
        body: atRiskTableBody,
        ..._autoTableDefaults(startY),
        columnStyles: {
            0: { fontStyle: 'bold' },
            1: { halign: 'center' },
        },
        didParseCell: (data) => {
            if (data.section === 'body' && data.column.index === 1) {
                const val = parseInt(data.cell.raw);
                if (val > 0) data.cell.styles.textColor = [220, 38, 38];
                else         data.cell.styles.textColor = [22, 163, 74];
            }
        },
        didDrawPage: () => _pdfFooter(),
    });
    _pdfSignature(doc.lastAutoTable.finalY);

    // ─────────────────────────────────────────────────────
    // PAGE 7 — NEW RESIDENTS REGISTERED
    // Same layout as generateNewResidentsPDF()
    // ─────────────────────────────────────────────────────
    doc.addPage();
    startY = _pdfHeader("New Residents Registered", "Monthly enrollment count over the last 6 months");

    // Bar chart
    startY = _captureChart('newResidentsChart', startY, 200, 75,
        "New Residents Registered", "Monthly enrollment count over the last 6 months");

    // Data from #statNewResidentsTable
    const newResTable = document.querySelector("#statNewResidentsTable");
    const newResRows  = [];
    if (newResTable) {
        newResTable.querySelectorAll("tbody tr").forEach(tr => {
            if (tr.style.display === 'none') return;
            const cells = tr.querySelectorAll('td');
            if (cells.length >= 2) newResRows.push([cells[0].textContent.trim(), cells[1].textContent.trim()]);
        });
    }
    const totalNew = newResRows.reduce((s, r) => s + (parseInt(r[1]) || 0), 0);
    doc.autoTable({
        head: [["Month", "New Registrations"]],
        body: newResRows.length > 0 ? newResRows : [["No data available", "—"]],
        ..._autoTableDefaults(startY),
        columnStyles: {
            0: { fontStyle: 'bold' },
            1: { halign: 'center' },
        },
        didDrawPage: () => _pdfFooter(),
    });
    const newFinalY = doc.lastAutoTable.finalY;
    doc.setFontSize(9);
    doc.setFont("helvetica", "bold");
    doc.setTextColor(26, 31, 54);
    doc.text("Total New Registrations (6 months): " + totalNew, leftMargin, newFinalY + 8);
    doc.setFont("helvetica", "normal");
    doc.setTextColor(0);
    _pdfSignature(newFinalY + 8);

    // ─────────────────────────────────────────────────────
    // PAGE 8 — HEALTH RECORDS TABLE
    // Same layout as generateHealthRecordsPDF()
    // ─────────────────────────────────────────────────────
    doc.addPage();
    const rawTitle   = document.querySelector("#statRecordsTable")
                        ?.closest(".rpt-stat-section")
                        ?.querySelector(".rpt-stat-title")
                        ?.textContent?.trim() || "Health Records Report";
    const monthTitle = rawTitle.replace("Health Records ·", "").trim();
    startY = _pdfHeader("Health Records Report — " + monthTitle, "Monthly health record data for all residents");

    const recTable = document.querySelector("#statRecordsTable");
    if (recTable) {
        const recBody = [];
        recTable.querySelectorAll("tbody tr").forEach(tr => {
            if (tr.style.display === 'none') return;
            const tds = tr.querySelectorAll('td');
            const bmiVal = tds[6]?.querySelector('.rpt-bmi-val')?.textContent?.trim()
                        || tds[6]?.textContent?.trim() || '';
            const statusBadge = tds[7]?.querySelector('.res-bmi-badge');
            let statusText = '';
            if (statusBadge) {
                const clone = statusBadge.cloneNode(true);
                clone.querySelector('.res-bmi-badge-dot')?.remove();
                statusText = clone.textContent.trim();
            } else {
                statusText = tds[7]?.textContent?.trim() || '—';
            }
            recBody.push([
                tds[0]?.textContent?.trim() || '',
                tds[1]?.textContent?.trim() || '',
                tds[2]?.textContent?.trim() || '',
                tds[3]?.textContent?.trim() || '',
                tds[4]?.textContent?.trim() || '',
                tds[5]?.textContent?.trim() || '',
                bmiVal,
                statusText,
                tds[8]?.textContent?.trim() || '',
            ]);
        });
        if (recBody.length > 0) {
            doc.autoTable({
                head: [["Date", "Resident", "Age", "Gender", "Weight", "Height", "BMI", "Status", "Recorded By"]],
                body: recBody,
                ..._autoTableDefaults(startY),
                columnStyles: {
                    0: { cellWidth: 25 },
                    6: { halign: 'center', cellWidth: 16 },
                    7: { cellWidth: 24 },
                },
                              didParseCell: (data) => {
                    if (data.section === 'body' && data.column.index === 6) {
                        data.cell.styles.fontStyle = 'bold';
                    }
                },
                didDrawPage: () => _pdfFooter(),
            });
            _pdfSignature(doc.lastAutoTable.finalY);
        } else {
            doc.setFontSize(9); doc.setTextColor(150);
            doc.text("No health records found for " + monthLabel + ".", leftMargin, startY + 6);
            doc.setTextColor(0);
        }
    }

    // ─────────────────────────────────────────────────────
    // PAGE 9 — PRIORITY CASES (AT-RISK)
    // ─────────────────────────────────────────────────────
    doc.addPage();
    startY = _pdfHeader("At-Risk Population Report", "Residents requiring immediate attention");

    const priorityTable = document.querySelector("#priorityTable");
    if (priorityTable) {
        const pBody = [];
        priorityTable.querySelectorAll("tbody tr").forEach(tr => {
            if (tr.style.display === 'none') return;
            const tds        = tr.querySelectorAll('td');
            const workerCell = tds[0]?.querySelector('.rpt-worker-cell');
            const name       = workerCell?.querySelector('.rpt-worker-name')?.textContent?.trim() || '';
            const addr       = workerCell?.querySelector('.rpt-worker-dept')?.textContent?.trim() || '';
            const recDiv     = tds[6]?.querySelector('.ai-rec-text');
            const wh         = (tds[4]?.textContent?.trim() || '').replace(/\s+/g, ' ');
            pBody.push([
                name + (addr ? '\n' + addr : ''),
                tds[1]?.textContent?.trim() || '',
                tds[2]?.querySelector('.rpt-bmi-val')?.textContent?.trim() || tds[2]?.textContent?.trim() || '',
                tds[3]?.querySelector('.rpt-bmi-pill')?.textContent?.trim() || tds[3]?.textContent?.trim() || '',
                wh,
                tds[5]?.textContent?.trim() || '',
                recDiv?.textContent?.trim() || tds[6]?.textContent?.trim() || '',
            ]);
        });
        if (pBody.length > 0) {
            doc.autoTable({
                head: [["Resident", "Gender / Age", "BMI", "Status", "Weight / Height", "Record Date", "AI Recommendation"]],
                body: pBody,
                ..._autoTableDefaults(startY),
                columnStyles: {
                    0: { cellWidth: 50 },
                    2: { halign: 'center', cellWidth: 16 },
                    3: { cellWidth: 26 },
                    4: { cellWidth: 30 },
                    5: { cellWidth: 26 },
                    6: { cellWidth: 60 },
                },
                            didParseCell: (data) => {
                    if (data.section === 'body' && data.column.index === 2) {
                        data.cell.styles.fontStyle = 'bold';
                    }
                },
                didDrawPage: () => _pdfFooter(),
            });
            _pdfSignature(doc.lastAutoTable.finalY);
        } else {
            doc.setFontSize(9); doc.setTextColor(22, 163, 74);
            doc.text("No at-risk residents found. All residents have a normal BMI.", leftMargin, startY + 6);
            doc.setTextColor(0);
        }
    }

    // ─────────────────────────────────────────────────────
    // PAGE 10 — UNASSESSED RESIDENTS
    // ─────────────────────────────────────────────────────
    doc.addPage();
    startY = _pdfHeader("Unassessed Residents Report", "Residents overdue or never assessed");

    // KPI strip
    const kpiCards = document.querySelectorAll('.rpt-kpi-card');
    if (kpiCards.length > 0) {
        const kpiW = (pageWidth - leftMargin * 2 - (kpiCards.length - 1) * 4) / kpiCards.length;
        let kx = leftMargin;
        kpiCards.forEach(card => {
            const label = card.querySelector('.rpt-kpi-label')?.textContent?.trim() || '';
            const value = card.querySelector('.rpt-kpi-value')?.textContent?.trim() || '—';
            const sub   = card.querySelector('.rpt-kpi-sub')?.textContent?.trim()   || '';
            doc.setFillColor(248, 250, 255);
            doc.setDrawColor(230, 235, 245);
            doc.setLineWidth(0.3);
            doc.roundedRect(kx, startY, kpiW, 20, 2, 2, 'FD');
            doc.setFont("helvetica", "bold"); doc.setFontSize(14);
            doc.setTextColor(26, 31, 54);
            doc.text(value, kx + kpiW / 2, startY + 9, { align: "center" });
            doc.setFont("helvetica", "normal"); doc.setFontSize(7);
            doc.setTextColor(100);
            doc.text(label, kx + kpiW / 2, startY + 14, { align: "center" });
            doc.setFontSize(6.5); doc.setTextColor(150);
            doc.text(sub, kx + kpiW / 2, startY + 18, { align: "center" });
            kx += kpiW + 4;
        });
        doc.setTextColor(0);
        startY += 26;
    }

    const unassessedTable = document.querySelector("#unassessedTable");
    if (unassessedTable) {
        const uBody = [];
        unassessedTable.querySelectorAll("tbody tr").forEach(tr => {
            if (tr.style.display === 'none') return;
            const tds        = tr.querySelectorAll('td');
            const workerCell = tds[0]?.querySelector('.rpt-worker-cell');
            const name       = workerCell?.querySelector('.rpt-worker-name')?.textContent?.trim() || '';
            const addr       = workerCell?.querySelector('.rpt-worker-dept')?.textContent?.trim() || '';
            const typeText   = tds[5]?.querySelector('.rpt-bmi-pill')?.textContent?.trim() || tds[5]?.textContent?.trim() || '';
            const lastBmi    = tds[6]?.querySelector('.rpt-bmi-val')?.textContent?.trim() || tds[6]?.textContent?.trim() || '';
            const metricEl   = tds[7]?.querySelector('.metric');
            let aiStatus = '';
            if (metricEl) {
                const mClone = metricEl.cloneNode(true);
                mClone.querySelector('.metric-dot')?.remove();
                aiStatus = mClone.textContent.trim();
            } else {
                aiStatus = tds[7]?.textContent?.trim() || '';
            }
            uBody.push([
                name + (addr ? '\n' + addr : ''),
                tds[1]?.textContent?.trim() || '',
                tds[2]?.textContent?.trim() || '',
                tds[3]?.textContent?.trim() || '',
                tds[4]?.textContent?.trim() || '',
                typeText,
                lastBmi || '—',
                aiStatus || '—',
            ]);
        });
        if (uBody.length > 0) {
            doc.autoTable({
                head: [["Resident", "Gender / Age", "Phone", "Last Record", "Days Since", "Type", "Last BMI", "Last AI Status"]],
                body: uBody,
                ..._autoTableDefaults(startY),
                columnStyles: {
                    0: { cellWidth: 55 },
                    6: { halign: 'center', cellWidth: 18 },
                },
                            didParseCell: (data) => {
                    if (data.section === 'body' && data.column.index === 6) {
                        const val = parseFloat(data.cell.raw);
                        if (!isNaN(val)) data.cell.styles.fontStyle = 'bold';
                    }
                },
                didDrawPage: () => _pdfFooter(),
            });
            _pdfSignature(doc.lastAutoTable.finalY);
        } else {
            doc.setFontSize(9); doc.setTextColor(22, 163, 74);
            doc.text("All residents have been assessed within the last 30 days.", leftMargin, startY + 6);
            doc.setTextColor(0);
        }
    }

    // ─────────────────────────────────────────────────────
    // PAGE 11 — CERTIFICATION & SIGNATURES
    // ─────────────────────────────────────────────────────
    doc.addPage();
    startY = _pdfHeader("Certification & Signatures", "Monthly Health Summary Report — " + monthLabel);

    doc.setFillColor(248, 250, 255);
    doc.setDrawColor(37, 99, 235);
    doc.setLineWidth(0.3);
    doc.roundedRect(leftMargin, startY, pageWidth - leftMargin - rightMargin, 30, 3, 3, 'FD');
    doc.setFont("helvetica", "bold"); doc.setFontSize(10);
    doc.setTextColor(26, 31, 54);
    doc.text("CERTIFICATION", pageWidth / 2, startY + 8, { align: "center" });
    doc.setFont("helvetica", "normal"); doc.setFontSize(8.5);
    doc.setTextColor(80);
    const certText  = "This is to certify that the health records and nutrition data contained in this report are true and accurate "
                + "as recorded by the health workers of Barangay " + (window.CURRENT_BARANGAY_NAME || "Health Center") + ", Calasiao, Pangasinan for the period of " + monthLabel + ".";
    const certLines = doc.splitTextToSize(certText, pageWidth - leftMargin - rightMargin - 16);
    doc.text(certLines, pageWidth / 2, startY + 16, { align: "center" });
    doc.setTextColor(0);
    startY += 38;

    const sigW = (pageWidth - leftMargin - rightMargin - 20) / 3;
    [
        { title: "Prepared by:", role: "Health Worker"           },
        { title: "Reviewed by:", role: "Barangay Health Officer" },
        { title: "Approved by:", role: "Barangay Captain"        },
    ].forEach((sig, i) => {
        const sx = leftMargin + i * (sigW + 10);
        doc.setFont("helvetica", "normal"); doc.setFontSize(9); doc.setTextColor(80);
        doc.text(sig.title, sx, startY);
        doc.setDrawColor(26, 31, 54); doc.setLineWidth(0.4);
        doc.line(sx, startY + 18, sx + sigW, startY + 18);
        doc.setFont("helvetica", "bold"); doc.setFontSize(8.5); doc.setTextColor(26, 31, 54);
        doc.text(sig.role, sx + sigW / 2, startY + 24, { align: "center" });
        doc.setFont("helvetica", "normal"); doc.setFontSize(8); doc.setTextColor(120);
        doc.text("Signature over printed name", sx + sigW / 2, startY + 30, { align: "center" });
        doc.text("Date: ____________________",  sx + sigW / 2, startY + 37, { align: "center" });
    });
    doc.setTextColor(0);
    startY += 50;

    doc.setFont("helvetica", "bold"); doc.setFontSize(9); doc.setTextColor(100);
    doc.text("OFFICIAL SEAL:", leftMargin, startY);
    doc.setDrawColor(180); doc.setLineWidth(0.2);
    doc.circle(leftMargin + 25, startY + 18, 18, 'S');
    doc.setFont("helvetica", "normal"); doc.setFontSize(7);
    doc.text("(Place official seal here)", leftMargin + 25, startY + 40, { align: "center" });

    doc.setFont("helvetica", "italic"); doc.setFontSize(7.5); doc.setTextColor(150);
    doc.text(
        "This document is computer-generated by NutriTrack Health Monitoring System. " +
        "For verification, contact the Barangay Health Center of Calasiao, San Miguel, Pangasinan.",
        pageWidth / 2, pageHeight - 14, { align: "center" }
    );

    _pdfFooter();

    // ─────────────────────────────────────────────────────
    // OPEN AND PRINT
    // ─────────────────────────────────────────────────────
    const pdfBlob = doc.output('blob');
    const pdfUrl  = URL.createObjectURL(pdfBlob);
    const win     = window.open(pdfUrl);
    if (win) {
        win.onload = () => { win.focus(); win.print(); };
    }
}