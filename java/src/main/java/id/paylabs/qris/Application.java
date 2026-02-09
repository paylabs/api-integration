package id.paylabs.qris;

import org.springframework.boot.SpringApplication;
import org.springframework.boot.autoconfigure.SpringBootApplication;

import io.github.cdimascio.dotenv.Dotenv;

@SpringBootApplication
public class Application {

    public static void main(String[] args) {
        Dotenv dotenv = Dotenv.configure().ignoreIfMissing().load();
        String port = dotenv.get("PORT", "3000");
        
        System.setProperty("server.port", port);
        
        System.out.println("Callback server listening on port " + port);
        SpringApplication.run(Application.class, args);
    }
}
