import time
from playwright.sync_api import sync_playwright, expect

def run(playwright):
    browser = playwright.chromium.launch(headless=True)
    page = browser.new_page()

    try:
        # Navigate to the page
        page.goto("http://localhost:8000/index.php", wait_until="networkidle")

        # Give it a second to make sure everything has rendered
        time.sleep(1)

        # Take a screenshot of the initial (dark) theme
        page.screenshot(path="jules-scratch/verification/initial-dark-mode.png")
        print("Took dark mode screenshot.")

        # Find and click the theme toggle button
        theme_toggle_button = page.locator("#theme-toggle")
        expect(theme_toggle_button).to_be_visible()
        theme_toggle_button.click()
        print("Clicked theme toggle button.")

        # Wait for the theme change to apply
        time.sleep(1) # Simple wait is fine for this visual check

        # Take a screenshot of the light theme
        page.screenshot(path="jules-scratch/verification/toggled-light-mode.png")
        print("Took light mode screenshot.")

        print("Screenshots taken successfully.")

    except Exception as e:
        print(f"An error occurred: {e}")
        # Take a screenshot on error to help debug
        page.screenshot(path="jules-scratch/verification/error.png")

    finally:
        browser.close()

with sync_playwright() as playwright:
    run(playwright)
