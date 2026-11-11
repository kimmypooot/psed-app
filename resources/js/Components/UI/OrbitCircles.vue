<script setup>
/**
 * The legacy dashboard banner's eight softly orbiting circles (old-imis
 * .welcome-banner ::before/::after and .orbit-circle-1…6), shared by every
 * banner on navy: the portal welcome and the admin / HR dashboards. Put it
 * first inside a `relative overflow-hidden` banner and lift the content
 * with `relative z-10`. Decorative; still when the user prefers reduced
 * motion.
 */

// Position, size, white opacity, orbit period (s), orbit radius (px), and direction.
const circles = [
    { style: 'top:20%;right:15%', size: 60, alpha: 0.12, duration: 10, radius: 25 },
    { style: 'bottom:25%;left:10%', size: 80, alpha: 0.08, duration: 14, radius: -35, reverse: true },
    { style: 'top:60%;right:25%', size: 40, alpha: 0.06, duration: 8, radius: 20 },
    { style: 'top:10%;left:20%', size: 50, alpha: 0.1, duration: 16, radius: -30, reverse: true },
    { style: 'bottom:10%;right:30%', size: 35, alpha: 0.05, duration: 12, radius: 28 },
    { style: 'top:40%;left:5%', size: 45, alpha: 0.07, duration: 18, radius: -25, reverse: true },
    { style: 'bottom:50%;right:5%', size: 30, alpha: 0.09, duration: 6, radius: 18 },
    { style: 'top:30%;left:35%', size: 25, alpha: 0.04, duration: 20, radius: -22, reverse: true },
];

const circleStyle = (circle) =>
    `${circle.style};width:${circle.size}px;height:${circle.size}px;` +
    `background:rgb(255 255 255 / ${circle.alpha});` +
    `--orbit-duration:${circle.duration}s;--orbit-radius:${circle.radius}px;` +
    (circle.reverse ? 'animation-direction:reverse;' : '');
</script>

<template>
    <span
        v-for="(circle, index) in circles"
        :key="index"
        class="orbit pointer-events-none absolute rounded-full"
        :style="circleStyle(circle)"
        aria-hidden="true"
    />
</template>

<style scoped>
/* The legacy orbit: each circle travels a small circle around its spot. */
@media (prefers-reduced-motion: no-preference) {
    .orbit {
        animation: orbit var(--orbit-duration) linear infinite;
        will-change: transform;
    }
}

@keyframes orbit {
    from {
        transform: rotate(0deg) translateX(var(--orbit-radius)) rotate(0deg);
    }
    to {
        transform: rotate(360deg) translateX(var(--orbit-radius)) rotate(-360deg);
    }
}
</style>
