#!/bin/bash
# setup.sh

# Update and upgrade system packages
sudo apt update && sudo apt upgrade -y

# Install Apache, PHP, required PHP modules, Git, wget, and unzip
sudo apt install -y apache2 php libapache2-mod-php php-mysql php-mbstring php-xml git wget unzip

# Enable Apache to start on boot and restart Apache
sudo systemctl enable apache2
sudo systemctl restart apache2

# Change to the Apache web root directory
cd /var/www/html

# Remove the default index file if it exists
sudo rm -f index.html

# Clone the repository from GitHub into the Apache web root
sudo git clone https://github.com/withalihassan/sender.git

# Change into the cloned repository directory
cd sender

# Checkout the "remote" branch
sudo git checkout remote

# Copy all files from the repository to the Apache web root
sudo cp -r ./* /var/www/html/

# Restart Apache to apply all changes
sudo systemctl restart apache2
