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
 * Full-state dashboard hero — warm gradient panel with a centred score ring
 * and supportive copy, with a three-stat row (Effective / Perceived / Trend)
 * tucked horizontally beneath the copy.
 *
 * Stateless; the parent ResponsivenessModule owns the full ↔ slim toggle.
 *
 * @module    block_feedback_tracker/components/ResponsivenessHero
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {html} from 'block_feedback_tracker/lib/preact';
import ScoreRing from 'block_feedback_tracker/components/ScoreRing';
import Badge from 'block_feedback_tracker/components/Badge';
import Sparkle from 'block_feedback_tracker/components/Sparkle';
import {formatHours} from 'block_feedback_tracker/lib/format';
import {classifySpeed} from 'block_feedback_tracker/lib/trend';

/**
 * @param {object} props
 * @param {number|null} props.score    Overall responsiveness 0..100.
 * @param {string|null} props.band     Band slug for colour selection.
 * @param {string} props.bandlabel
 * @param {number|null} props.effectivehours  Median effective hours.
 * @param {string} props.perceivedlabel       Display of perceived calendar wait (e.g. "4d").
 * @param {number|null} props.trendpct        Trend %; negative = faster.
 * @param {object} props.i18n
 * @param {() => void} [props.onCollapse]     Click handler for the floating collapse button.
 * @param {object} [props.config]             Dashboard config bundle (feature flags).
 * @returns {object} vnode
 */
export default function ResponsivenessHero({score, band, bandlabel, effectivehours, perceivedlabel,
    trendpct, i18n, onCollapse, config}) {
    const display = score === null || score === undefined ? '—' : Math.round(Number(score));
    const trend = classifySpeed(trendpct);
    const trendTone = trend.colour;
    const trendArrow = trend.arrow;
    const trendText = trend.magnitude;
    // eslint-disable-next-line no-undef
    const wwwroot = (typeof M !== 'undefined' && M.cfg && M.cfg.wwwroot) || '';
    const simulatorurl = wwwroot + '/blocks/feedback_tracker/pages/score_simulator.php';
    const showsimulator = !!(config && config.enable_teacher_simulator);

    return html`
        <section class=${'bft-rh bft-rh-tone-' + (band || 'pending')}>
            ${onCollapse && html`
                <button type="button"
                        class="bft-rh-collapse"
                        title=${i18n.dashboard_collapse || 'Collapse to slim view'}
                        aria-label=${i18n.dashboard_collapse || 'Collapse to slim view'}
                        onClick=${onCollapse}>
                    ${i18n.dashboard_collapse || 'Collapse'}
                </button>
            `}
            <span class="bft-rh-sparkle bft-rh-sparkle-1" aria-hidden="true"><${Sparkle} size=${12} /></span>
            <span class="bft-rh-sparkle bft-rh-sparkle-2" aria-hidden="true"><${Sparkle} size=${8} /></span>
            <span class="bft-rh-sparkle bft-rh-sparkle-3" aria-hidden="true"><${Sparkle} size=${10} /></span>

            <div class="bft-rh-ring-col">
                <div class="bft-rh-ring-wrap">
                    <${ScoreRing} score=${score} band=${band} size=${120} thickness=${11} />
                    <div class="bft-rh-ring-center">
                        <span class=${'bft-rh-score bft-mono bft-overall-score-tone-' + (band || 'pending')}>
                            ${display}
                        </span>
                        <span class="bft-rh-score-of bft-mono">/ 100</span>
                    </div>
                </div>
                <${Badge} band=${band} label=${bandlabel} />
            </div>

            <div class="bft-rh-copy">
                <div class="bft-rh-eyebrow">
                    ${i18n.dashboard_hero_eyebrow || 'Your academic responsiveness'}
                </div>
                <div class="bft-rh-headline">
                    ${i18n.dashboard_hero_headline || 'Here is how things are flowing this month.'}
                </div>
                <div class="bft-rh-body">
                    ${i18n.dashboard_hero_body
                        || 'Score uses business-time only — weekends, holidays and recess are paused and excluded.'}
                </div>

                <aside class="bft-rh-mini-col">
                    <div class="bft-rh-mini">
                        <div class="bft-rh-mini-label">${i18n.hero_effective_eyebrow || 'Effective'}</div>
                        <div class=${'bft-rh-mini-val bft-mono bft-overall-score-tone-' + (band || 'pending')}>
                            ${effectivehours === null ? '—' : formatHours(effectivehours)}
                        </div>
                        <div class="bft-rh-mini-sub">${i18n.hero_effective_unit || 'business hrs'}</div>
                    </div>
                    <div class="bft-rh-mini-divider"></div>
                    <div class="bft-rh-mini">
                        <div class="bft-rh-mini-label">${i18n.hero_perceived_label || 'Perceived'}</div>
                        <div class="bft-rh-mini-val bft-mono bft-overall-score-tone-pending">
                            ${perceivedlabel}
                        </div>
                        <div class="bft-rh-mini-sub">${i18n.hero_perceived_unit || 'calendar days'}</div>
                    </div>
                    <div class="bft-rh-mini-divider"></div>
                    <div class="bft-rh-mini">
                        <div class="bft-rh-mini-label">${i18n.hero_trend_eyebrow || 'Trend'}</div>
                        <div class=${'bft-rh-mini-val bft-mono bft-overall-score-tone-' + trendTone}>
                            ${trendArrow} ${trendText}
                        </div>
                        <div class="bft-rh-mini-sub">${i18n.hero_trend_unit || 'vs last month'}</div>
                    </div>
                    ${showsimulator && [
                        html`<div key="simdiv" class="bft-rh-mini-divider"></div>`,
                        html`<a key="simlink" class="bft-rh-sim-link" href=${simulatorurl}>
                            ${i18n.dashboard_simulator_button || 'Simulator'}
                        </a>`,
                    ]}
                </aside>
            </div>
        </section>
    `;
}
