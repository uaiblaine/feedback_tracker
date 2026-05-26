// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Teacher dashboard view (Phase 3E redesign) — warm, supportive
 * cross-course overview.
 *
 * Composition (top → bottom):
 *   1. Brand-tag eyebrow ("FEEDBACK TRACKER")
 *   2. Greeting H1 (time-of-day aware) + WaveMark
 *   3. Subline (pending count · critical count · business-time chip)
 *   4. ResponsivenessModule — full hero ↔ slim strip (collapsible)
 *   5. Insights row (Bright spot / Most improved / Gentle watch)
 *   6. "Grade now · picked for you" — 3 priority cards
 *   7. "Your courses" table with inline ScoreRing + sparkline + Open link
 *
 * Initial payload comes from the mount-point JSON so the first paint is
 * data-rich. Insights lazy-load on mount.
 *
 * @module    block_feedback_tracker/views/DashboardView
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {html, useState, useMemo, useEffect} from 'block_feedback_tracker/lib/preact';
import ResponsivenessModule from 'block_feedback_tracker/components/ResponsivenessModule';
import InsightCard from 'block_feedback_tracker/components/InsightCard';
import PriorityCard from 'block_feedback_tracker/components/PriorityCard';
import CoursesTable from 'block_feedback_tracker/components/CoursesTable';
import WaveMark from 'block_feedback_tracker/components/WaveMark';
import {getDashboard, getGraderPriorityList, getInsights}
    from 'block_feedback_tracker/lib/api';
import {bandForScore} from 'block_feedback_tracker/lib/bands';

/**
 * Aggregate per-course rows into a single hero score + total counters.
 *
 * @param {Array<object>} courses
 * @returns {{pending: number, critical: number, overgoal: number,
 *            avgscore: number|null, effective: number|null,
 *            compliance: number|null, trendpct: number|null}}
 */
const aggregate = (courses) => {
    let pending = 0;
    let critical = 0;
    let overgoal = 0;
    let scoreSum = 0;
    let scoreWeight = 0;
    let effSum = 0;
    let effCount = 0;
    let compSum = 0;
    let compCount = 0;
    let trendSum = 0;
    let trendCount = 0;
    (courses || []).forEach((c) => {
        pending += Number(c.pending) || 0;
        critical += Number(c.critical) || 0;
        overgoal += Number(c.overgoal) || 0;
        if (c.avgscore !== null && c.avgscore !== undefined) {
            const weight = Math.max(1, Number(c.pending) || 0);
            scoreSum += Number(c.avgscore) * weight;
            scoreWeight += weight;
        }
        if (c.median_eff_h !== null && c.median_eff_h !== undefined) {
            effSum += Number(c.median_eff_h);
            effCount += 1;
        }
        if (c.compliance_pct !== null && c.compliance_pct !== undefined) {
            compSum += Number(c.compliance_pct);
            compCount += 1;
        }
        if (c.trend_pct_30d !== null && c.trend_pct_30d !== undefined) {
            trendSum += Number(c.trend_pct_30d);
            trendCount += 1;
        }
    });
    return {
        pending,
        critical,
        overgoal,
        avgscore:   scoreWeight > 0 ? scoreSum / scoreWeight : null,
        effective:  effCount > 0 ? effSum / effCount : null,
        compliance: compCount > 0 ? compSum / compCount : null,
        trendpct:   trendCount > 0 ? trendSum / trendCount : null,
    };
};

/**
 * Pure client-side sort for the courses table.
 *
 * @param {Array<object>} rows
 * @param {string|null} sortKey
 * @param {string} sortOrder
 * @returns {Array<object>}
 */
const sortCourses = (rows, sortKey, sortOrder) => {
    if (!sortKey || !Array.isArray(rows)) {
        return rows;
    }
    const dir = sortOrder === 'asc' ? 1 : -1;
    const numeric = ['pending', 'critical', 'overgoal', 'avgscore', 'median_eff_h'];
    const numkey = numeric.indexOf(sortKey) !== -1;
    const copy = rows.slice();
    copy.sort((a, b) => {
        const va = a[sortKey];
        const vb = b[sortKey];
        if (numkey) {
            return ((Number(va) || 0) - (Number(vb) || 0)) * dir;
        }
        return String(va || '').localeCompare(String(vb || '')) * dir;
    });
    return copy;
};

/**
 * Pick the time-of-day greeting string key from local clock.
 *
 * @returns {string}
 */
const greetingKey = () => {
    const h = new Date().getHours();
    if (h < 12) {
        return 'dashboard_greeting_morning';
    }
    if (h < 18) {
        return 'dashboard_greeting_afternoon';
    }
    return 'dashboard_greeting_evening';
};

/**
 * Rough perceived calendar-days from business hours (8h day, with weekend
 * inflation). Returns a string suffix like "4d" or "—".
 *
 * @param {number|null|undefined} effectivehours
 * @returns {string}
 */
const perceivedLabel = (effectivehours) => {
    const n = Number(effectivehours);
    if (!Number.isFinite(n) || n <= 0) {
        return '—';
    }
    return Math.max(1, Math.round(n / 24 * 1.35)) + 'd';
};

/**
 * @param {object} props
 * @param {object} props.initial   Mount-point payload: {greeting, dashboard,
 *                                 gradenow, cancompare, i18n, config}.
 * @returns {object} vnode
 */
export default function DashboardView({initial}) {
    const i18n = initial.i18n || {};
    const config = initial.config || {};
    const scoreThresholds = config.score_thresholds || null;
    const dashboard = initial.dashboard || {};

    const [courses, setCourses] = useState(
        Array.isArray(dashboard.courses) ? dashboard.courses : []
    );
    const [refreshing, setRefreshing] = useState(false);
    const [error, setError] = useState(null);
    const [sortKey, setSortKey] = useState('pending');
    const [sortOrder, setSortOrder] = useState('desc');
    const [gradenow, setGradenow] = useState(initial.gradenow || null);
    const [gradenowError, setGradenowError] = useState(null);
    const [insights, setInsights] = useState(initial.insights || null);

    const sorted = useMemo(() => sortCourses(courses, sortKey, sortOrder), [courses, sortKey, sortOrder]);
    const totals = useMemo(() => aggregate(courses), [courses]);
    const heroBand = bandForScore(totals.avgscore, scoreThresholds);
    const heroBandLabel = (i18n.bands || {})[heroBand] || '';

    // Local-clock greeting, recomputed every render (cheap).
    const greetingTemplate = i18n[greetingKey()]
        || i18n.dashboard_hero_greeting
        || 'Hi there, {$a->firstname}';
    const greeting = greetingTemplate.replace('{$a->firstname}', initial.greeting_firstname || '');

    /**
     * Refresh courses + grade-now + insights in parallel.
     */
    const handleRefresh = async () => {
        if (refreshing) {
            return;
        }
        setRefreshing(true);
        setError(null);
        setGradenowError(null);
        try {
            const [resCourses, resGradenow, resInsights] = await Promise.all([
                getDashboard({}),
                getGraderPriorityList({limit: 3}),
                getInsights(),
            ]);
            if (resCourses && Array.isArray(resCourses.courses)) {
                setCourses(resCourses.courses);
            }
            if (resGradenow) {
                setGradenow(resGradenow);
            }
            if (resInsights) {
                setInsights(resInsights);
            }
        } catch (e) {
            setError(i18n.dashboard_error || 'Failed to refresh.');
        } finally {
            setRefreshing(false);
        }
    };

    // Lazy-load insights on mount if the server didn't preload them.
    useEffect(() => {
        if (insights !== null) {
            return;
        }
        getInsights()
            .then((res) => setInsights(res || {}))
            .catch(() => setInsights({}));
    }, []);

    const handleSort = (key) => {
        if (sortKey === key) {
            setSortOrder(sortOrder === 'asc' ? 'desc' : 'asc');
        } else {
            setSortKey(key);
            setSortOrder(key === 'coursename' ? 'asc' : 'desc');
        }
    };

    const priorities = (gradenow && Array.isArray(gradenow.submissions))
        ? gradenow.submissions.slice(0, 3) : [];

    // Build the hero props once so both the full and slim variants get the same shape.
    const heroprops = {
        score: totals.avgscore,
        band: heroBand,
        bandlabel: heroBandLabel,
        effectivehours: totals.effective,
        perceivedlabel: perceivedLabel(totals.effective),
        compliancepct: totals.compliance,
        trendpct: totals.trendpct,
        i18n,
    };

    return html`
        <div class="bft-dashboard">
            <header class="bft-dashboard-header">
                <div class="bft-dashboard-header-text">
                    <div class="bft-dashboard-brandtag">
                        ${i18n.dashboard_brandtag || 'Feedback tracker'}
                    </div>
                    <h1 class="bft-dashboard-h1">
                        ${greeting}
                        <span class="bft-dashboard-wave"><${WaveMark} size=${26} /></span>
                    </h1>
                    <div class="bft-dashboard-subline">
                        <strong>${totals.pending}</strong>
                        <span>${i18n.dashboard_subline_waiting || 'waiting'}</span>
                        <span class="bft-dashboard-dot">·</span>
                        <strong class=${totals.critical > 0 ? 'bft-overall-score-tone-critical' : ''}>
                            ${totals.critical}
                        </strong>
                        <span>${i18n.dashboard_subline_critical || 'critical'}</span>
                        <span class="bft-dashboard-dot">·</span>
                        <span class="bft-dashboard-business-chip">
                            ${i18n.dashboard_business_chip
                                || 'Business-time only · weekends, holidays & recess paused'}
                        </span>
                    </div>
                </div>
                <button type="button"
                        class=${'bft-refresh' + (refreshing ? ' bft-refresh-busy' : '')}
                        disabled=${refreshing}
                        title=${i18n.dashboard_refresh || 'Refresh'}
                        aria-label=${i18n.dashboard_refresh || 'Refresh'}
                        onClick=${handleRefresh}>⟳</button>
            </header>

            ${error && html`<div class="bft-error" role="alert">${error}</div>`}

            <${ResponsivenessModule} heroprops=${heroprops} />

            ${insights && (insights.bright_spot || insights.most_improved || insights.gentle_watch) && html`
                <section class="bft-dashboard-insights">
                    <div class="bft-dashboard-section-eyebrow">
                        ${i18n.dashboard_insights_title || 'Insights'}
                    </div>
                    <div class="bft-insights-row">
                        ${insights.bright_spot && html`
                            <${InsightCard}
                                tone="bright"
                                eyebrow=${i18n.insight_brightspot_eyebrow || "This week's bright spot"}
                                title=${insights.bright_spot.coursename}
                                metric_value=${insights.bright_spot.metric_value}
                                metric_suffix=${insights.bright_spot.metric_suffix}
                                body=${i18n.insight_brightspot_body || ''} />
                        `}
                        ${insights.most_improved && html`
                            <${InsightCard}
                                tone="climbing"
                                eyebrow=${insights.most_improved.momentum
                                    ? (i18n.insight_momentum_eyebrow || "This week's momentum")
                                    : (i18n.insight_mostimproved_eyebrow || 'Most improved')}
                                title=${insights.most_improved.coursename}
                                metric_value=${insights.most_improved.metric_value}
                                metric_suffix=${insights.most_improved.metric_suffix}
                                body=${insights.most_improved.momentum
                                    ? (i18n.insight_momentum_body || '')
                                    : (i18n.insight_mostimproved_body || '')} />
                        `}
                        ${insights.gentle_watch && html`
                            <${InsightCard}
                                tone="watch"
                                eyebrow=${i18n.insight_gentlewatch_eyebrow || 'Gentle watch'}
                                title=${insights.gentle_watch.coursename}
                                metric_value=${insights.gentle_watch.metric_value}
                                metric_suffix=${insights.gentle_watch.metric_suffix}
                                body=${i18n.insight_gentlewatch_body || ''} />
                        `}
                    </div>
                </section>
            `}

            ${priorities.length > 0 && html`
                <section class="bft-dashboard-priorities">
                    <div class="bft-dashboard-section-eyebrow">
                        ${i18n.dashboard_priority_title || 'Grade now · picked for you'}
                    </div>
                    ${gradenowError && html`<div class="bft-error" role="alert">${gradenowError}</div>`}
                    <div class="bft-priority-row">
                        ${priorities.map((sub, i) => html`
                            <${PriorityCard}
                                key=${'p-' + (sub.submissionid || i)}
                                idx=${i + 1}
                                submission=${sub}
                                i18n=${i18n} />
                        `)}
                    </div>
                </section>
            `}

            <section class="bft-dashboard-courses">
                <div class="bft-dashboard-section-eyebrow">
                    ${i18n.dashboard_courses_title || 'Your courses'}
                </div>
                <${CoursesTable}
                    rows=${sorted}
                    i18n=${i18n}
                    sortKey=${sortKey}
                    sortOrder=${sortOrder}
                    onSort=${handleSort}
                    thresholds=${scoreThresholds} />
            </section>
        </div>
    `;
}
