(() => {
    'use strict';

    const $ = (selector, root = document) => root.querySelector(selector);
    const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));

    function initNavigation() {
        const sidebar = $('#sidebar');
        const toggle = $('#navToggle');
        const scrim = $('#navScrim');
        if (!sidebar || !toggle || !scrim) {
            return;
        }

        const close = () => {
            sidebar.classList.remove('is-open');
            scrim.classList.remove('is-open');
        };

        toggle.addEventListener('click', () => {
            sidebar.classList.toggle('is-open');
            scrim.classList.toggle('is-open');
        });
        scrim.addEventListener('click', close);
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                close();
            }
        });
    }

    function initFlashMessages() {
        $$('.flash-close').forEach((button) => {
            button.addEventListener('click', () => {
                button.closest('.flash')?.remove();
            });
        });
    }

    function initConfirmation() {
        $$('form[data-confirm]').forEach((form) => {
            form.addEventListener('submit', (event) => {
                if (!window.confirm(form.dataset.confirm || '确认执行此操作？')) {
                    event.preventDefault();
                }
            });
        });
    }

    function initCopyButtons() {
        $$('[data-copy]').forEach((button) => {
            button.addEventListener('click', async () => {
                const content = button.getAttribute('data-copy') || '';
                try {
                    await navigator.clipboard.writeText(content);
                    const previous = button.textContent;
                    button.textContent = '已复制';
                    window.setTimeout(() => {
                        button.textContent = previous;
                    }, 1400);
                } catch {
                    button.textContent = '复制失败';
                }
            });
        });
    }

    function initAutoSubmit() {
        $$('[data-auto-submit]').forEach((field) => {
            field.addEventListener('change', () => field.form?.submit());
        });
    }

    function prepareCanvas(canvas) {
        const rect = canvas.getBoundingClientRect();
        const dpr = Math.min(window.devicePixelRatio || 1, 2);
        const width = Math.max(1, rect.width);
        const height = Math.max(1, rect.height);
        canvas.width = Math.round(width * dpr);
        canvas.height = Math.round(height * dpr);
        const ctx = canvas.getContext('2d');
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        return { ctx, width, height };
    }

    function formatValue(value, unit) {
        if (value === null || value === undefined) {
            return '—';
        }
        const number = Number(value);
        const text = Number.isInteger(number) ? String(number) : number.toFixed(number >= 100 ? 0 : 1);
        return unit === '%' ? text + '%' : (unit ? text + ' ' + unit : text);
    }

    function drawChart(canvas, config) {
        if (!config || !Array.isArray(config.series) || !config.series.length) {
            return;
        }
        const { ctx, width, height } = prepareCanvas(canvas);
        const labels = Array.isArray(config.labels) ? config.labels : [];
        const pad = { top: 13, right: 13, bottom: 26, left: 39 };
        const plotWidth = Math.max(1, width - pad.left - pad.right);
        const plotHeight = Math.max(1, height - pad.top - pad.bottom);

        let allValues = [];
        config.series.forEach((series) => {
            (series.values || []).forEach((value) => {
                if (value !== null && value !== undefined && Number.isFinite(Number(value))) {
                    allValues.push(Number(value));
                }
            });
        });
        if (!allValues.length) {
            return;
        }

        const hasMin = config.min !== null && config.min !== undefined && config.min !== '' && Number.isFinite(Number(config.min));
        const hasMax = config.max !== null && config.max !== undefined && config.max !== '' && Number.isFinite(Number(config.max));
        let min = hasMin ? Number(config.min) : Math.min(...allValues);
        let max = hasMax ? Number(config.max) : Math.max(...allValues);
        if (min === max) {
            min = Math.max(0, min - 1);
            max += 1;
        }
        const range = max - min;
        if (!hasMin) {
            min = Math.max(0, min - range * 0.12);
        }
        if (!hasMax) {
            max += range * 0.12;
        }

        const xFor = (index) => {
            if (labels.length <= 1) {
                return pad.left + plotWidth / 2;
            }
            return pad.left + (plotWidth * index) / (labels.length - 1);
        };
        const yFor = (value) => pad.top + plotHeight - ((Number(value) - min) / (max - min)) * plotHeight;

        ctx.clearRect(0, 0, width, height);

        const gridSteps = 4;
        ctx.font = '9.5px "Segoe UI", "Microsoft YaHei", sans-serif';
        ctx.textBaseline = 'middle';
        for (let index = 0; index <= gridSteps; index++) {
            const y = pad.top + (plotHeight * index) / gridSteps;
            ctx.beginPath();
            ctx.strokeStyle = index === gridSteps ? 'rgba(63, 79, 95, 0.62)' : 'rgba(52, 67, 82, 0.36)';
            ctx.lineWidth = 1;
            ctx.setLineDash(index === gridSteps ? [] : [3, 5]);
            ctx.moveTo(pad.left, y);
            ctx.lineTo(width - pad.right, y);
            ctx.stroke();
            ctx.setLineDash([]);

            const value = max - ((max - min) * index) / gridSteps;
            ctx.fillStyle = '#617185';
            ctx.textAlign = 'right';
            ctx.fillText(formatValue(value, config.unit), pad.left - 9, y);
        }

        if (Array.isArray(config.thresholds)) {
            config.thresholds.forEach((threshold) => {
                const value = Number(threshold.value);
                if (!Number.isFinite(value) || value < min || value > max) {
                    return;
                }
                const y = yFor(value);
                ctx.save();
                ctx.strokeStyle = threshold.color || 'rgba(255, 107, 107, 0.62)';
                ctx.lineWidth = 1;
                ctx.setLineDash([5, 4]);
                ctx.beginPath();
                ctx.moveTo(pad.left, y);
                ctx.lineTo(width - pad.right, y);
                ctx.stroke();
                ctx.restore();

                if (threshold.label) {
                    ctx.fillStyle = threshold.color || '#ff9d9d';
                    ctx.font = '9px "Segoe UI", "Microsoft YaHei", sans-serif';
                    ctx.textAlign = 'left';
                    ctx.textBaseline = 'bottom';
                    ctx.fillText(threshold.label, pad.left + 3, y - 3);
                }
            });
        }

        ctx.font = '9.5px "Segoe UI", "Microsoft YaHei", sans-serif';
        ctx.textBaseline = 'top';
        const xStep = Math.max(1, Math.ceil(labels.length / 7));
        labels.forEach((label, index) => {
            if (index % xStep !== 0 && index !== labels.length - 1) {
                return;
            }
            ctx.fillStyle = '#617185';
            ctx.textAlign = index === 0 ? 'left' : (index === labels.length - 1 ? 'right' : 'center');
            ctx.fillText(String(label), xFor(index), height - pad.bottom + 9);
        });

        config.series.forEach((series) => {
            const values = Array.isArray(series.values) ? series.values : [];
            const color = series.color || '#f0a94b';
            const points = [];
            values.forEach((value, index) => {
                if (value === null || value === undefined || !Number.isFinite(Number(value))) {
                    return;
                }
                points.push({ x: xFor(index), y: yFor(value), index, value: Number(value) });
            });
            if (!points.length) {
                return;
            }

            if (series.fill !== false) {
                const gradient = ctx.createLinearGradient(0, pad.top, 0, pad.top + plotHeight);
                gradient.addColorStop(0, hexToRgba(color, 0.20));
                gradient.addColorStop(1, hexToRgba(color, 0.005));
                ctx.beginPath();
                ctx.moveTo(points[0].x, pad.top + plotHeight);
                points.forEach((point) => ctx.lineTo(point.x, point.y));
                ctx.lineTo(points[points.length - 1].x, pad.top + plotHeight);
                ctx.closePath();
                ctx.fillStyle = gradient;
                ctx.fill();
            }

            ctx.save();
            ctx.beginPath();
            ctx.strokeStyle = color;
            ctx.lineWidth = 1.8;
            ctx.lineJoin = 'round';
            ctx.lineCap = 'round';
            if (series.fill !== false) {
                ctx.shadowColor = hexToRgba(color, 0.32);
                ctx.shadowBlur = 9;
            }
            points.forEach((point, pointIndex) => {
                if (pointIndex === 0) {
                    ctx.moveTo(point.x, point.y);
                } else {
                    ctx.lineTo(point.x, point.y);
                }
            });
            ctx.stroke();
            ctx.restore();

            const last = points[points.length - 1];
            ctx.beginPath();
            ctx.fillStyle = color;
            ctx.arc(last.x, last.y, 3, 0, Math.PI * 2);
            ctx.fill();
            ctx.beginPath();
            ctx.fillStyle = '#111821';
            ctx.arc(last.x, last.y, 1.2, 0, Math.PI * 2);
            ctx.fill();
        });
    }

    function hexToRgba(hex, alpha) {
        if (!/^#[0-9a-f]{6}$/i.test(hex)) {
            return 'rgba(240, 169, 75, ' + alpha + ')';
        }
        const value = hex.slice(1);
        const red = parseInt(value.slice(0, 2), 16);
        const green = parseInt(value.slice(2, 4), 16);
        const blue = parseInt(value.slice(4, 6), 16);
        return 'rgba(' + red + ', ' + green + ', ' + blue + ', ' + alpha + ')';
    }

    function initCharts() {
        const canvases = $$('canvas[data-chart]');
        if (!canvases.length) {
            return;
        }

        const drawAll = () => {
            canvases.forEach((canvas) => {
                try {
                    const config = JSON.parse(canvas.getAttribute('data-chart') || '{}');
                    drawChart(canvas, config);
                } catch {
                    // A malformed chart payload should not affect the rest of the page.
                }
            });
        };

        drawAll();
        let resizeTimer = 0;
        window.addEventListener('resize', () => {
            window.clearTimeout(resizeTimer);
            resizeTimer = window.setTimeout(drawAll, 120);
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        initNavigation();
        initFlashMessages();
        initConfirmation();
        initCopyButtons();
        initAutoSubmit();
        initCharts();
    });
})();
