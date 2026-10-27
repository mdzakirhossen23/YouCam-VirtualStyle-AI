# Devpost Submission Copy — English

## Name
VirtualStyle AI — Inclusive Multilingual Apparel Try-On

## Elevator pitch
See how an outfit might look on you before buying it, with a multilingual virtual try-on experience powered by YouCam AI Clothes V4.

## Inspiration
Online fashion shoppers often have difficulty imagining a garment on their own body. We wanted to provide a simple, language-accessible product exploration experience.

## What it does
Users upload a full-body photo and a garment image. The app securely submits a virtual try-on request to YouCam AI Clothes V4 through its PHP backend, polls the task, and displays the generated try-on image. The app offers five interface languages (English, Bengali, Arabic, Dutch, and German), five dark themes, saved looks in a local Wardrobe, and local History. A rule-based Style Advisor gives simple outfit guidance.

## How we built it
HTML/CSS/JavaScript frontend, PHP server-side API proxy, YouCam Clothes V4 REST API. Photos are submitted as temporary hosted URLs and tasks are polled until completion.

## Challenges
Secure handling of API credentials, displaying full-body photos in a resizable layout, managing asynchronous AI tasks, and improving file upload state.

## Accomplishments
A working live virtual try-on flow shown on our deployed prototype, multilingual user interface, and convenient saved-look browsing.

## What is next
Private user storage, improved security and image deletion, accessible mobile experience, and richer personalized recommendations.

## Demo URL
https://virtualstyle.baharsoftbd.com/

## Code URL
[Add GitHub repo URL after uploading]

## Demo video URL
[Add public YouTube/Vimeo URL after publishing]

> Check all claims against the final deployed version before submitting. Do not claim the local Style Advisor is YouCam-generated AI.
