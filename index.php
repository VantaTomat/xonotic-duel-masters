<?php
// index.php (shell)
$prevFolder = "";
include($prevFolder . "_intro.php");

// Start Page
$PAGE_NAME = "Welcome To - ";

include($prevFolder . "assets/_header.php");

?>
<!-- Main inject container -->
<main id="app-root" class="container" role="main" tabindex="-1">
	
	<!-- Hero Section -->
    <div style="text-align: center; margin: 80px 0;">
        <h1 style="font-size: 2.6rem; font-weight: bold; margin-bottom: 30px; margin-top: 0px;">
            Community tournaments for Xonotic
        </h1>
        <p style="font-size: 1.2rem; color: #aaa; max-width: 700px; margin: auto;">
            A free, open-source website for organizing Xonotic tournaments.
            Built for the community to host events, share the excitement, and have a good time competing.
        </p>
        
        <div style="display:flex; align-items:center;justify-content:center;gap:30px;margin-top:30px;;">
        	<a class="btn" style="clip-path: polygon(0 0,100% 0,100% calc(100% - 8px),calc(100% - 8px) 100%,0 100%);" href="<?php echo htmlspecialchars(MAIN_ROOT . 'tournaments/create.php'); ?>">Create Tournament</a>
       		<a class="btn" style="background-color:transparent;border:1px solid #00FF41;color:#00FF41;" href="<?php echo htmlspecialchars(MAIN_ROOT . 'competitions.php'); ?>">Explore Tournaments</a>     
        </div>
        
    </div>

	<!-- About -->
    <div style="margin: 80px 0;">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 25px;">
            <!-- What is this? -->
            <div style="background: #1110; border: 1px solid rgba(0,255,65,0.2); padding: 25px; border-radius: 8px; text-align: center;">
                <div style="font-size: 2rem; margin-bottom: 10px;">🎮</div>
                <h3 style="margin-bottom: 10px;">What Is This?</h3>
                <p style="color: #aaa;">
                    A community website dedicated to organizing tournaments for Xonotic players.
                    It is completely free, open source, and made for fun.
                </p>
            </div>
            <!-- Tournaments -->
            <div style="background: #1110; border: 1px solid rgba(0,255,65,0.2); padding: 25px; border-radius: 8px; text-align: center;">
                <div style="font-size: 2rem; margin-bottom: 10px;">🏆</div>
                <h3 style="margin-bottom: 10px;">Tournaments</h3>
                <p style="color: #aaa;">
                    Join or create community events, compete in ongoing tournaments, and follow brackets, schedules, and results.
                </p>
            </div>
            <!-- Getting Started -->
            <div style="background: #1110; border: 1px solid rgba(0,255,65,0.2); padding: 25px; border-radius: 8px; text-align: center;">
                <div style="font-size: 2rem; margin-bottom: 10px;">⚔️</div>
                <h3 style="margin-bottom: 10px;">Getting Started</h3>
                <p style="color: #aaa;">
                    Browse upcoming tournaments, register for the ones you like, and have fun competing with other players.
                </p>
            </div>
        </div>
    </div>

</main>

<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "SoftwareSourceCode",
  "name": "Xonotic Duel Masters Organizer",
  "description": "Open source tournament platform for the game Xonotic.",
  "codeRepository": "https://github.com/VantaTomat/community-tournaments-platform",
  "programmingLanguage": "PHP", 
  "author": {
	"@type": "Person",
	"name": "VantaTomat"
  }
}
</script>
	
	
<?php
include($prevFolder . "assets/_footer.php");
?>
