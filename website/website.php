<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="../uploads/Eastern_Samar_State_University_logo.ico" type="image/x-icon">
    <title>Eastern Samar State University - Grading System</title>
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <link rel="stylesheet" href="../Font-Awesome-master/css/all.min.css">
    <script src="../js/jquery-3.6.0.min.js"></script>
    <script src="../js/popper.min.js"></script>
    <script src="../js/bootstrap.min.js"></script>
    
    <style>
        /* Advanced Professional Website Styles */
        :root {
            /* Professional Color Palette */
            --primary-dark-green: #0f3d29;
            --secondary-dark-green: #1a5f47;
            --tertiary-dark-green: #2d7a5f;
            --accent-gold: #c9a227;
            --light-gold: #e6c847;
            --pale-gold: #f4e5c2;
            --pure-white: #ffffff;
            --off-white: #f8f9fa;
            --light-gray: #e9ecef;
            --medium-gray: #6c757d;
            --dark-gray: #343a40;
            --charcoal: #2c3e50;
            --soft-black: #1a1d23;
            --deep-black: #0d1117;
            --border-light: rgba(255, 255, 255, 0.1);
            --border-medium: rgba(255, 255, 255, 0.2);
            --shadow-light: rgba(0, 0, 0, 0.1);
            --shadow-medium: rgba(0, 0, 0, 0.2);
            --shadow-heavy: rgba(0, 0, 0, 0.3);
            --gradient-primary: linear-gradient(135deg, var(--primary-dark-green), var(--secondary-dark-green));
            --gradient-accent: linear-gradient(135deg, var(--accent-gold), var(--light-gold));
            --gradient-overlay: linear-gradient(180deg, rgba(13, 17, 23, 0.8), rgba(26, 29, 35, 0.9));
        }

        /* Advanced Animations */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(40px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @keyframes slideInLeft {
            from { opacity: 0; transform: translateX(-50px); }
            to { opacity: 1; transform: translateX(0); }
        }
        
        @keyframes slideInRight {
            from { opacity: 0; transform: translateX(50px); }
            to { opacity: 1; transform: translateX(0); }
        }
        
        @keyframes shimmer {
            0% { background-position: -2000px 0; }
            100% { background-position: 2000px 0; }
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.05); opacity: 0.8; }
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
        }
        
        @keyframes glow {
            0%, 100% { box-shadow: 0 0 5px var(--accent-gold); }
            50% { box-shadow: 0 0 20px var(--accent-gold), 0 0 30px var(--accent-gold); }
        }
        
        @keyframes typewriter {
            from { width: 0; }
            to { width: 100%; }
        }
        
        @keyframes blink {
            0%, 50% { border-color: var(--pure-white); }
            51%, 100% { border-color: transparent; }
        }

        /* Modern Typography & Base Styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', 'Segoe UI', 'Helvetica Neue', Arial, sans-serif;
            font-weight: 400;
            line-height: 1.6;
            background: var(--gradient-primary);
            color: var(--pure-white);
            min-height: 100vh;
            overflow-x: hidden;
            position: relative;
            font-size: 16px;
        }
        
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: 
                radial-gradient(circle at 20% 30%, rgba(201, 162, 39, 0.15) 0%, transparent 50%),
                radial-gradient(circle at 80% 70%, rgba(15, 61, 41, 0.25) 0%, transparent 50%),
                radial-gradient(circle at 50% 50%, rgba(230, 200, 71, 0.08) 0%, transparent 70%);
            pointer-events: none;
            z-index: 1;
        }
        
        /* Modern Scrollbar */
        ::-webkit-scrollbar {
            width: 10px;
        }
        
        ::-webkit-scrollbar-track {
            background: var(--dark-gray);
        }
        
        ::-webkit-scrollbar-thumb {
            background: var(--accent-gold);
            border-radius: 5px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: var(--light-gold);
        }

        /* Modern Navigation Header */
        .navbar-custom {
            background: rgba(26, 29, 35, 0.95);
            backdrop-filter: blur(25px);
            -webkit-backdrop-filter: blur(25px);
            border-bottom: 2px solid var(--accent-gold);
            padding: 1rem 0;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            transition: all 0.3s ease;
            box-shadow: 0 4px 20px var(--shadow-heavy);
        }

        .navbar-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 700;
            font-size: 1.1rem;
            color: var(--pure-white) !important;
            text-decoration: none;
            text-transform: uppercase;
            letter-spacing: 1px;
            transition: all 0.3s ease;
        }

        .navbar-brand:hover {
            transform: translateY(-1px);
        }

        .navbar-brand img {
            width: 35px;
            height: 35px;
            border-radius: 8px;
            border: 2px solid var(--accent-gold);
            box-shadow: 0 2px 10px rgba(201, 162, 39, 0.3);
            transition: all 0.3s ease;
        }

        .navbar-brand:hover img {
            box-shadow: 0 4px 15px rgba(201, 162, 39, 0.5);
        }

        .navbar-nav .nav-link {
            color: var(--pure-white) !important;
            font-weight: 600;
            margin: 0 0.3rem;
            padding: 0.6rem 1.2rem !important;
            border-radius: 25px;
            transition: all 0.3s ease;
            position: relative;
            font-size: 0.95rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border: 1px solid transparent;
        }

        .navbar-nav .nav-link::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: var(--gradient-accent);
            border-radius: 25px;
            opacity: 0;
            transition: opacity 0.3s ease;
            z-index: -1;
        }

        .navbar-nav .nav-link:hover {
            color: var(--pure-white) !important;
            transform: translateY(-2px);
            border-color: var(--accent-gold);
            box-shadow: 0 4px 15px rgba(201, 162, 39, 0.3);
        }

        .navbar-nav .nav-link:hover::before {
            opacity: 0.1;
        }

        .navbar-nav .nav-link.active {
            color: var(--pure-white) !important;
            background: var(--gradient-primary);
            border-color: var(--accent-gold);
            box-shadow: 0 4px 15px rgba(15, 61, 41, 0.3);
        }

        .navbar-toggler {
            border: 2px solid var(--accent-gold);
            color: var(--pure-white);
            padding: 0.4rem 0.6rem;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .navbar-toggler:hover {
            background: var(--accent-gold);
            color: var(--soft-black);
        }

        .navbar-toggler:focus {
            box-shadow: 0 0 0 0.25rem rgba(201, 162, 39, 0.25);
        }

        /* Modern Hero Section */
        .hero-section {
            padding: 140px 0 100px;
            position: relative;
            overflow: hidden;
            min-height: 100vh;
            display: flex;
            align-items: center;
        }

        .hero-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: 
                radial-gradient(circle at 25% 25%, rgba(201, 162, 39, 0.2) 0%, transparent 40%),
                radial-gradient(circle at 75% 75%, rgba(15, 61, 41, 0.3) 0%, transparent 40%);
            pointer-events: none;
            z-index: 1;
        }

        .hero-content {
            position: relative;
            z-index: 3;
            text-align: center;
            max-width: 800px;
            margin: 0 auto;
        }

        .hero-title {
            font-size: clamp(2.5rem, 6vw, 4rem);
            font-weight: 900;
            color: var(--pure-white);
            text-shadow: 0 4px 8px rgba(0, 0, 0, 0.5);
            margin-bottom: 2rem;
            text-transform: uppercase;
            letter-spacing: 3px;
            line-height: 1.1;
            animation: fadeInUp 1s ease-out;
            background: linear-gradient(135deg, var(--pure-white), var(--light-gold));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .hero-subtitle {
            font-size: clamp(1.2rem, 3vw, 1.8rem);
            color: var(--accent-gold);
            font-weight: 700;
            margin-bottom: 2rem;
            text-transform: uppercase;
            letter-spacing: 2px;
            text-shadow: 0 2px 6px rgba(0, 0, 0, 0.4);
            animation: fadeInUp 1s ease-out 0.2s both;
            position: relative;
        }

        .hero-subtitle::after {
            content: '';
            position: absolute;
            bottom: -10px;
            left: 50%;
            transform: translateX(-50%);
            width: 100px;
            height: 3px;
            background: var(--gradient-accent);
            border-radius: 2px;
            animation: shimmer 2s infinite;
        }

        .hero-description {
            font-size: clamp(1rem, 2vw, 1.3rem);
            color: var(--off-white);
            line-height: 1.7;
            margin-bottom: 3rem;
            opacity: 0.9;
            text-shadow: 0 1px 3px rgba(0, 0, 0, 0.3);
            animation: fadeInUp 1s ease-out 0.4s both;
            font-weight: 400;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }

        .cta-buttons {
            display: flex;
            gap: 1.5rem;
            flex-wrap: wrap;
            animation: fadeInUp 1s ease-out 0.6s both;
            justify-content: center;
        }

        .btn-primary-custom {
            background: var(--gradient-primary);
            color: var(--pure-white);
            border: none;
            padding: 1.2rem 2.5rem;
            font-weight: 700;
            border-radius: 50px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.8rem;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            position: relative;
            overflow: hidden;
            font-size: 1rem;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            box-shadow: 0 8px 25px rgba(15, 61, 41, 0.4);
            border: 2px solid var(--accent-gold);
        }

        .btn-primary-custom::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(201, 162, 39, 0.4), transparent);
            transition: left 0.6s ease;
        }

        .btn-primary-custom:hover {
            transform: translateY(-3px) scale(1.05);
            box-shadow: 0 12px 35px rgba(15, 61, 41, 0.5);
            color: var(--pure-white);
        }
        
        .btn-primary-custom:hover::before {
            left: 100%;
        }

        .btn-secondary-custom {
            background: transparent;
            color: var(--pure-white);
            border: 2px solid var(--accent-gold);
            padding: 1.2rem 2.5rem;
            font-weight: 700;
            border-radius: 50px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.8rem;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            font-size: 1rem;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            position: relative;
            overflow: hidden;
        }

        .btn-secondary-custom::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: var(--gradient-accent);
            transition: left 0.4s ease;
            z-index: -1;
        }

        .btn-secondary-custom:hover {
            color: var(--soft-black);
            transform: translateY(-3px) scale(1.05);
            box-shadow: 0 12px 35px rgba(201, 162, 39, 0.4);
        }

        .btn-secondary-custom:hover::before {
            left: 0;
        }

        /* Modern Hero Image Slideshow */
        .hero-slideshow {
            position: relative;
            height: 500px;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 20px 60px var(--shadow-heavy);
            animation: float 6s ease-in-out infinite;
        }

        .slide {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            transition: all 1s ease;
        }

        .slide.active {
            opacity: 1;
        }

        .slideshow-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        /* Slide Transitions */
        .slide.fade-in { animation: fadeIn 1s ease-out; }
        .slide.slide-left { animation: slideInLeft 1s ease-out; }
        .slide.slide-right { animation: slideInRight 1s ease-out; }
        .slide.zoom-in { animation: zoomIn 1s ease-out; }
        .slide.slide-up { animation: slideUp 1s ease-out; }
        .slide.slide-down { animation: slideDown 1s ease-out; }

        @keyframes zoomIn {
            from { opacity: 0; transform: scale(0.8); }
            to { opacity: 1; transform: scale(1); }
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(50px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-50px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Modern Features Section */
        .features-section {
            padding: 100px 0;
            background: var(--gradient-overlay);
            position: relative;
            overflow: hidden;
        }

        .features-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: 
                radial-gradient(circle at 30% 20%, rgba(201, 162, 39, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 70% 80%, rgba(15, 61, 41, 0.15) 0%, transparent 50%);
            pointer-events: none;
        }

        .section-title {
            font-size: clamp(2rem, 5vw, 3rem);
            font-weight: 900;
            text-align: center;
            margin-bottom: 1rem;
            color: var(--pure-white);
            text-shadow: 0 3px 6px rgba(0, 0, 0, 0.4);
            text-transform: uppercase;
            letter-spacing: 2px;
            position: relative;
            z-index: 2;
        }

        .section-subtitle {
            text-align: center;
            color: var(--accent-gold);
            margin-bottom: 4rem;
            font-size: clamp(1rem, 2.5vw, 1.3rem);
            font-weight: 500;
            position: relative;
            z-index: 2;
        }

        .feature-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid var(--border-light);
            border-radius: 25px;
            padding: 2.5rem;
            height: 100%;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            position: relative;
            overflow: hidden;
            box-shadow: 0 15px 40px var(--shadow-medium);
            z-index: 2;
        }

        .feature-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: var(--gradient-accent);
            background-size: 200% 100%;
            animation: shimmer 3s infinite;
        }

        .feature-card::after {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(201, 162, 39, 0.1) 0%, transparent 70%);
            opacity: 0;
            transition: opacity 0.4s ease;
            pointer-events: none;
        }

        .feature-card:hover {
            transform: translateY(-12px) scale(1.02);
            box-shadow: 0 25px 60px var(--shadow-heavy);
            border-color: var(--accent-gold);
        }
        
        .feature-card:hover::before {
            animation-duration: 1.5s;
        }

        .feature-card:hover::after {
            opacity: 1;
        }

        .feature-icon {
            width: 70px;
            height: 70px;
            background: var(--gradient-primary);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 2rem;
            font-size: 1.8rem;
            color: var(--pure-white);
            box-shadow: 0 8px 25px rgba(15, 61, 41, 0.3);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .feature-icon::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transition: left 0.5s ease;
        }

        .feature-card:hover .feature-icon {
            transform: scale(1.1) rotate(5deg);
            box-shadow: 0 12px 35px rgba(15, 61, 41, 0.4);
        }

        .feature-card:hover .feature-icon::before {
            left: 100%;
        }

        .feature-title {
            font-size: clamp(1.1rem, 2.5vw, 1.4rem);
            font-weight: 800;
            margin-bottom: 1.2rem;
            color: var(--primary-dark-green);
            text-transform: uppercase;
            letter-spacing: 0.8px;
            transition: color 0.3s ease;
        }

        .feature-card:hover .feature-title {
            color: var(--accent-gold);
        }

        .feature-description {
            color: var(--dark-gray);
            line-height: 1.7;
            font-size: clamp(0.9rem, 2vw, 1.1rem);
            font-weight: 400;
            transition: color 0.3s ease;
        }

        .feature-card:hover .feature-description {
            color: var(--charcoal);
        }

        /* Advanced Particle Background System */
        .particles {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 1;
            overflow: hidden;
        }

        .particle {
            position: absolute;
            background: var(--accent-gold);
            border-radius: 50%;
            pointer-events: none;
            opacity: 0.3;
            animation: float-particle 20s infinite linear;
        }

        @keyframes float-particle {
            0% {
                transform: translateY(100vh) translateX(0) rotate(0deg);
                opacity: 0;
            }
            10% {
                opacity: 0.3;
            }
            90% {
                opacity: 0.3;
            }
            100% {
                transform: translateY(-100vh) translateX(100px) rotate(360deg);
                opacity: 0;
            }
        }

        .particle.secondary {
            background: var(--light-gold);
            animation-duration: 25s;
            animation-delay: -5s;
        }

        .particle.tertiary {
            background: var(--pure-white);
            animation-duration: 30s;
            animation-delay: -10s;
        }

        /* Circuit Board Nodes */
        .circuit-node {
            position: fixed;
            width: 4px;
            height: 4px;
            background: var(--accent-gold);
            border-radius: 50%;
            pointer-events: none;
            z-index: 1;
            opacity: 0.6;
            animation: pulse-node 3s infinite;
        }

        .circuit-node.secondary {
            background: var(--light-gold);
            animation-delay: -1.5s;
        }

        @keyframes pulse-node {
            0%, 100% {
                opacity: 0.3;
                transform: scale(1);
            }
            50% {
                opacity: 0.8;
                transform: scale(1.5);
            }
        }

        /* Modern Stats Section */
        .stats-section {
            padding: 100px 0;
            background: var(--gradient-overlay);
            position: relative;
            overflow: hidden;
        }

        .stats-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: 
                radial-gradient(circle at 25% 25%, rgba(15, 61, 41, 0.2) 0%, transparent 50%),
                radial-gradient(circle at 75% 75%, rgba(201, 162, 39, 0.15) 0%, transparent 50%);
            pointer-events: none;
        }

        .stat-card {
            text-align: center;
            padding: 2.5rem;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid var(--border-light);
            border-radius: 25px;
            box-shadow: 0 15px 40px var(--shadow-medium);
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            position: relative;
            overflow: hidden;
            z-index: 2;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--gradient-accent);
            background-size: 200% 100%;
            animation: shimmer 3s infinite;
        }

        .stat-card::after {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(15, 61, 41, 0.1) 0%, transparent 70%);
            opacity: 0;
            transition: opacity 0.4s ease;
            pointer-events: none;
        }
        
        .stat-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 25px 60px var(--shadow-heavy);
            border-color: var(--accent-gold);
        }

        .stat-card:hover::after {
            opacity: 1;
        }

        .stat-number {
            font-size: clamp(2.5rem, 6vw, 3.5rem);
            font-weight: 900;
            color: var(--primary-dark-green);
            margin-bottom: 1rem;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            background: linear-gradient(135deg, var(--primary-dark-green), var(--accent-gold));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .stat-label {
            color: var(--dark-gray);
            font-size: clamp(0.9rem, 2vw, 1.1rem);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.8px;
        }

        /* Modern Footer */
        footer {
            background: var(--soft-black);
            border-top: 3px solid var(--accent-gold);
            padding: 4rem 0 2rem;
            margin-top: 100px;
            position: relative;
            overflow: hidden;
        }

        footer::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: 
                radial-gradient(circle at 20% 30%, rgba(201, 162, 39, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 80% 70%, rgba(15, 61, 41, 0.15) 0%, transparent 50%);
            pointer-events: none;
        }

        .footer-brand {
            display: flex;
            align-items: center;
            gap: 15px;
            font-weight: 800;
            font-size: 1.3rem;
            color: var(--pure-white);
            margin-bottom: 1.5rem;
            text-transform: uppercase;
            letter-spacing: 1.2px;
            position: relative;
            z-index: 2;
        }

        .footer-brand img {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            border: 2px solid var(--accent-gold);
            box-shadow: 0 4px 15px rgba(201, 162, 39, 0.3);
            transition: all 0.3s ease;
        }

        .footer-brand:hover img {
            transform: scale(1.1) rotate(5deg);
            box-shadow: 0 6px 20px rgba(201, 162, 39, 0.5);
        }

        .footer-links {
            list-style: none;
            padding: 0;
            position: relative;
            z-index: 2;
        }

        .footer-links li {
            margin-bottom: 1rem;
        }

        .footer-links a {
            color: var(--pure-white);
            text-decoration: none;
            transition: all 0.3s ease;
            font-weight: 500;
            font-size: 1rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .footer-links a:hover {
            color: var(--accent-gold);
            transform: translateX(5px);
        }

        .footer-links a i {
            font-size: 0.9rem;
            opacity: 0.7;
            transition: all 0.3s ease;
        }

        .footer-links a:hover i {
            opacity: 1;
        }

        .footer-bottom {
            border-top: 1px solid rgba(201, 162, 39, 0.3);
            margin-top: 3rem;
            padding-top: 2rem;
            text-align: center;
            color: var(--pure-white);
            opacity: 0.8;
            position: relative;
            z-index: 2;
            font-size: 0.95rem;
        }

        /* Social Media Links */
        .social-links {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
            position: relative;
            z-index: 2;
        }

        .social-links a {
            width: 40px;
            height: 40px;
            background: var(--gradient-accent);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--soft-black);
            text-decoration: none;
            transition: all 0.3s ease;
            font-size: 1.1rem;
        }

        .social-links a:hover {
            transform: translateY(-3px) scale(1.1);
            box-shadow: 0 8px 25px rgba(201, 162, 39, 0.4);
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .hero-title {
                font-size: 2.2rem;
            }
            
            .hero-subtitle {
                font-size: 1.2rem;
            }
            
            .hero-description {
                font-size: 1rem;
            }
            
            .section-title {
                font-size: 2rem;
            }
            
            .feature-card {
                margin-bottom: 2rem;
            }
            
            .stat-number {
                font-size: 2.5rem;
            }
        }

        @media (max-width: 576px) {
            .hero-title {
                font-size: 2rem;
            }
            
            .hero-subtitle {
                font-size: 1.1rem;
            }

            .hero-description {
                font-size: 0.95rem;
            }

            .hero-slideshow {
                height: 240px;
            }

            .hero-section {
                padding: 80px 0 40px;
            }

            .cta-buttons {
                flex-direction: column;
                gap: 0.8rem;
            }

            .btn-primary-custom,
            .btn-secondary-custom {
                width: 100%;
                margin-bottom: 0;
                padding: 0.8rem 1.5rem;
                font-size: 0.95rem;
            }

            .features-section {
                padding: 40px 0;
            }

            .stats-section {
                padding: 40px 0;
            }

            .section-title {
                font-size: 1.8rem;
            }

            .section-subtitle {
                font-size: 1rem;
            }

            .feature-card {
                padding: 1.2rem;
                margin-bottom: 1rem;
            }

            .feature-icon {
                width: 50px;
                height: 50px;
                font-size: 1.2rem;
            }

            .feature-title {
                font-size: 1.1rem;
            }

            .feature-description {
                font-size: 0.9rem;
            }

            .stat-card {
                padding: 1.2rem;
                margin-bottom: 1rem;
            }

            .stat-number {
                font-size: 2rem;
            }

            .stat-label {
                font-size: 0.9rem;
            }

            footer {
                padding: 2rem 0 1rem;
                margin-top: 40px;
            }

            .footer-brand {
                font-size: 1.1rem;
            }

            .footer-brand img {
                width: 30px;
                height: 30px;
            }

            .navbar-brand {
                font-size: 1.2rem;
            }

            .navbar-brand img {
                width: 35px;
                height: 35px;
            }

            .container {
                padding-left: 15px;
                padding-right: 15px;
            }
        }

        @media (max-width: 480px) {
            .hero-slideshow {
                height: 200px;
            }

            .hero-title {
                font-size: 1.9rem;
                line-height: 1.1;
            }
            
            .hero-subtitle {
                font-size: 1rem;
            }

            .hero-description {
                font-size: 0.95rem;
            }

            .section-title {
                font-size: 1.6rem;
            }

            .stat-number {
                font-size: 1.8rem;
            }

            .feature-card {
                padding: 1rem;
            }

            .stat-card {
                padding: 1rem;
            }
        }

        @media (max-width: 400px) {
            .hero-title {
                font-size: 1.7rem;
            }

            .hero-slideshow {
                height: 180px;
            }

            .btn-primary-custom,
            .btn-secondary-custom {
                font-size: 0.9rem;
                padding: 0.7rem 1.2rem;
            }

            .section-title {
                font-size: 1.5rem;
            }

            .stat-number {
                font-size: 1.6rem;
            }
        }

        @media (max-width: 360px) {
            .hero-title {
                font-size: 1.6rem;
            }

            .hero-slideshow {
                height: 160px;
            }

            .container {
                padding-left: 10px;
                padding-right: 10px;
            }
        }

        /* Ensure proper scaling on all devices */
        html {
            -webkit-text-size-adjust: 100%;
            -ms-text-size-adjust: 100%;
            text-size-adjust: 100%;
        }

        body {
            overflow-x: hidden;
            width: 100%;
            min-width: 320px;
            max-width: 100vw;
        }

        .container {
            max-width: 100%;
            width: 100%;
            margin-left: auto;
            margin-right: auto;
            padding-left: 15px;
            padding-right: 15px;
        }

        img {
            max-width: 100%;
            height: auto;
            display: block;
        }

        /* Better viewport scaling */
        .hero-title {
            font-size: clamp(1.6rem, 8vw, 3.5rem);
        }

        .hero-subtitle {
            font-size: clamp(1rem, 4vw, 1.5rem);
        }

        .section-title {
            font-size: clamp(1.5rem, 5vw, 2.5rem);
        }

        .hero-slideshow {
            height: clamp(220px, 100vh, 450px);
        }
    </style>
</head>
<body>
    <!-- Particles Background -->
    <div class="particles" id="particles"></div>

    <!-- Navigation Header -->
    <nav class="navbar navbar-expand-lg navbar-custom">
        <div class="container">
            <a class="navbar-brand" href="#home">
                <img src="../uploads/Eastern_Samar_State_University_logo.ico" alt="ESSU Logo">
                Easter Samar State University
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link active" href="#home">Home</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#features">Features</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#about">About</a>
                    </li>
                <!--    <li class="nav-item">
                        <a class="nav-link" href="../login.php">Login</a>
                    </li> -->
                    <li class="nav-item">
                        <a class="nav-link" href="../landingpage.php">Register</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero-section" id="home">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-6">
                    <div class="hero-content">
                        <h1 class="hero-title">College of Computer Studies</h1>
                        <p class="hero-subtitle">Eastern Samar State University</p>
                        <p class="hero-description">
                            A comprehensive digital solution for student grade management, attendance tracking, and academic performance monitoring. Designed for educators, students, and administrators.
                        </p>
                        <div class="cta-buttons">
                            <a href="../landingpage.php" class="btn-primary-custom">
                                <i class="fas fa-sign-in-alt"></i>
                                Get Started
                            </a>
                            <a href="#features" class="btn-secondary-custom">
                                <i class="fas fa-info-circle"></i>
                                Learn More
                            </a>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="hero-slideshow position-relative">
                        <div class="slide active">
                            <img src="../uploads/576367341_1145614967689944_551398696288194612_n.jpg" alt="ESSU" class="slideshow-image">
                        </div>
                        <div class="slide">
                            <img src="../uploads/578253112_1145517907699650_6149186453612660024_n.jpg" alt="ESSU" class="slideshow-image">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <!-- Features Section -->
    <section class="features-section" id="features">
        <div class="container">
            <h2 class="section-title">Powerful Features</h2>
            <p class="section-subtitle">Everything you need for efficient grade management</p>
            
            <div class="row g-4">
                <div class="col-md-4">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-graduation-cap"></i>
                        </div>
                        <h3 class="feature-title">Student Management</h3>
                        <p class="feature-description">
                            Comprehensive student registration, profile management, and academic record keeping with easy search and filtering capabilities.
                        </p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <h3 class="feature-title">Grade Tracking</h3>
                        <p class="feature-description">
                            Real-time grade monitoring, automatic calculations, and detailed performance analytics with customizable grading scales.
                        </p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <h3 class="feature-title">Class Management</h3>
                        <p class="feature-description">
                            Organize classes, manage schedules, assign subjects, and track enrollment with intuitive interface design.
                        </p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-file-alt"></i>
                        </div>
                        <h3 class="feature-title">Report Generation</h3>
                        <p class="feature-description">
                            Generate comprehensive reports, grade sheets, and academic transcripts with customizable templates and export options.
                        </p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-qrcode"></i>
                        </div>
                        <h3 class="feature-title">QR Code System</h3>
                        <p class="feature-description">
                            Secure QR code generation for student identification, attendance tracking, and quick data access.
                        </p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-shield-alt"></i>
                        </div>
                        <h3 class="feature-title">Secure Access</h3>
                        <p class="feature-description">
                            Role-based access control, user authentication, and data protection with advanced security measures.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Stats Section -->
    <section class="stats-section" id="about">
        <div class="container">
            <div class="row">
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-number">1000+</div>
                        <div class="stat-label">Students Enrolled</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-number">50+</div>
                        <div class="stat-label">Courses Offered</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-number">100+</div>
                        <div class="stat-label">Faculty Members</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-number">99.9%</div>
                        <div class="stat-label">System Uptime</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer>
        <div class="container">
            <div class="row">
                <div class="col-md-4">
                    <div class="footer-brand">
                        <img src="../uploads/Eastern_Samar_State_University_logo.ico" alt="ESSU Logo">
                        ESSU Grading System
                    </div>
                    <p class="text-secondary">
                        Eastern Samar State University - Can-Avid Campus<br>
                        Computer Studies Department
                    </p>
                </div>
                <div class="col-md-4">
                    <h5 class="text-primary mb-3">Quick Links</h5>
                    <ul class="footer-links">
                  <!--      <li><a href="../login.php">Faculty Login</a></li> -->
                        <li><a href="../landingpage.php">Student Registration</a></li>
                        <li><a href="#features">Features</a></li>
                        <li><a href="#about">About System</a></li>
                    </ul>
                </div>
                <div class="col-md-4">
                    <h5 class="text-primary mb-3">Contact Info</h5>
                    <ul class="footer-links">
                        <li><i class="fas fa-map-marker-alt me-2"></i> Can-Avid, Eastern Samar</li>
                        <li><i class="fas fa-phone me-2"></i> +63 XXX XXX XXXX</li>
                        <li><i class="fas fa-envelope me-2"></i> info@essu.edu.ph</li>
                    </ul>
                </div>
            </div>
            <div class="footer-bottom">
                <p>&copy; 2024 Eastern Samar State University. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <script>
        // Create floating particles
        document.addEventListener('DOMContentLoaded', function() {
            const particlesContainer = document.getElementById('particles');
            const particleCount = 50;
            
            for (let i = 0; i < particleCount; i++) {
                createParticle();
            }
            
            function createParticle() {
                const particle = document.createElement('div');
                particle.className = 'particle';
                
                const size = Math.random() * 3 + 1;
                const posX = Math.random() * 100;
                const duration = Math.random() * 20 + 10;
                const delay = Math.random() * 20;
                const opacity = Math.random() * 0.3 + 0.1;
                
                particle.style.width = `${size}px`;
                particle.style.height = `${size}px`;
                particle.style.left = `${posX}%`;
                particle.style.animationDuration = `${duration}s`;
                particle.style.animationDelay = `${delay}s`;
                particle.style.opacity = opacity;
                
                const colors = ['rgba(0, 242, 234, 0.7)', 'rgba(168, 85, 247, 0.7)', 'rgba(245, 158, 11, 0.7)'];
                particle.style.background = colors[Math.floor(Math.random() * colors.length)];
                
                particlesContainer.appendChild(particle);
            }

            // Image slideshow functionality
            const slides = document.querySelectorAll('.slide');
            let currentSlide = 0;
            const transitions = [
                'fade-in', 
                'slide-left', 
                'slide-right', 
                'zoom-in', 
                'slide-up', 
                'slide-down', 
                'rotate-in', 
                'flip-horizontal', 
                'flip-vertical', 
                'blur-in', 
                'slide-diagonal'
            ];

            function showSlide(index) {
                // Remove all transition classes and active state
                slides.forEach(slide => {
                    slide.classList.remove('active', ...transitions);
                });

                // Apply random transition to the new slide
                const randomTransition = transitions[Math.floor(Math.random() * transitions.length)];
                slides[index].classList.add(randomTransition);
                
                // Trigger the active state after a brief delay to ensure transition works
                setTimeout(() => {
                    slides[index].classList.add('active');
                }, 50);
            }

            function nextSlide() {
                currentSlide = (currentSlide + 1) % slides.length;
                showSlide(currentSlide);
            }

            // Change slide every 5 seconds
            setInterval(nextSlide, 5000);

            // Create circuit board nodes
            createCircuitNodes();
        });

        function createCircuitNodes() {
            const nodeCount = 15;
            const body = document.body;
            
            for (let i = 0; i < nodeCount; i++) {
                const node = document.createElement('div');
                node.className = Math.random() > 0.5 ? 'circuit-node' : 'circuit-node secondary';
                
                // Random positioning avoiding edges
                const posX = Math.random() * 90 + 5; // 5% to 95%
                const posY = Math.random() * 90 + 5; // 5% to 95%
                
                node.style.left = `${posX}%`;
                node.style.top = `${posY}%`;
                node.style.animationDelay = `${Math.random() * 3}s`;
                
                body.appendChild(node);
            }
        }

        // Smooth scrolling for navigation links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });

        // Update active nav link on scroll
        window.addEventListener('scroll', function() {
            const sections = document.querySelectorAll('section[id]');
            const navLinks = document.querySelectorAll('.navbar-nav .nav-link');
            
            let current = '';
            sections.forEach(section => {
                const sectionTop = section.offsetTop;
                const sectionHeight = section.clientHeight;
                if (window.pageYOffset >= sectionTop - 100) {
                    current = section.getAttribute('id');
                }
            });

            navLinks.forEach(link => {
                link.classList.remove('active');
                if (link.getAttribute('href') === `#${current}`) {
                    link.classList.add('active');
                }
            });
        });

        // Navbar background on scroll
        window.addEventListener('scroll', function() {
            const navbar = document.querySelector('.navbar-custom');
            if (window.scrollY > 50) {
                navbar.style.background = 'rgba(15, 23, 42, 0.98)';
            } else {
                navbar.style.background = 'rgba(15, 23, 42, 0.95)';
            }
        });
    </script>
</body>
</html>