<template>
  <svg :width="size" :height="size" viewBox="5 5 38 38" xmlns="http://www.w3.org/2000/svg" fill="none">
    <circle cx="16" cy="16" r="10" fill="#D62300"/>
    <circle cx="32" cy="16" r="10" fill="#F5A623"/>
    <circle cx="16" cy="32" r="10" fill="#FF8733"/>
    <circle cx="32" cy="32" r="10" fill="#FFD54F"/>
    <circle cx="24" cy="24" r="8.5" fill="#502314"/>
    <text x="24" y="29" text-anchor="middle" fill="white" font-size="14" font-weight="900" font-family="Arial, sans-serif">S</text>
  </svg>
</template>

<script setup>
defineProps({
  size: { type: Number, default: 32 },
});
</script>
